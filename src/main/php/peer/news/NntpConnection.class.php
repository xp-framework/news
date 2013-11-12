<?php namespace peer\news;
 
use peer\Socket;
use peer\ProtocolException;
use peer\URL;
use util\Date;
use util\log\Traceable;

/**
 * NNTP Connection
 *
 * Usage [retrieve newsgroup listing]:
 * <code>
 *   $c= new NntpConnection('nntp://news.xp-framework.net');
 *   try {
 *     $c->connect();
 *     $groups= $c->getGroups();
 *     $c->close();
 *   } catch(IOException $e) {
 *     $e->printStackTrace();
 *     exit();
 *   }
 *   
 *   foreach ($groups as $group) {
 *     var_dump($group->getName());
 *   }
 * </code>
 *
 * @see   rfc://977
 */
class NntpConnection extends \lang\Object implements Traceable {
  public
    $url      = null,
    $cat      = null,
    $response = array();

  /**
   * Constructor
   *
   * @param   peer.URL url
   */
  public function __construct($url) {
    $this->url= $url;
    $this->_sock= new Socket(
      $this->url->getHost(),
      $this->url->getPort(119)
    );
  }

  /**
   * Set a trace for debugging
   *
   * @param   util.log.LogCategory cat
   */
  public function setTrace($cat) {
    $this->cat= $cat;
  }
  
  /**
   * Wrapper that sends a command to the remote host.
   *
   * @param   string format
   * @param   var* args
   * @return  bool success
   * @throws  peer.ProtocolException in case the command is too long
   */
  protected function _sendcmd() {
    if (!$this->_sock->isConnected()) return false;

    $a= func_get_args();
    $cmd= implode(' ', $a);

    // NNTP/RFC977 only allows command up to 512 (-2) chars.
    if (strlen($cmd) > 510) {
      throw new ProtocolException('Command too long! Max. 510 chars');
    }
    
    $this->cat && $this->cat->debug('>>>', $cmd);
    try {
      $this->_sock->write($cmd."\r\n");
    } catch (\peer\SocketException $e) {
      return false;
    }
    
    // read first line and return
    // nntp statuscode
    return $this->_readResponse();
  }

  /**
   * Get status response
   *
   * @return  string status
   */
  protected function _readResponse() {
    if (!($line= $this->_sock->readLine())) return false;
    $this->cat && $this->cat->debug('<<<', $line);
    
    $this->response= array(
      (int) substr($line, 0, 3),
      (string) rtrim(substr($line, 4))
    );
    return $this->response[0];
  }

  /**
   * Get data
   *
   * @return  string status
   */
  protected function _readData() {
    if ($this->_sock->eof()) return false;

    $line= $this->_sock->readLine();
    $this->cat && $this->cat->debug('<<<', $line);

    if ('.' == $line) return false;
    return $line;
  }

  /**
   * Connect
   *
   * @param   float timeout default 2.0
   * @return  bool success
   * @throws  peer.ConnectException in case there's an error during connecting
   */
  public function connect($auth= false) {
    $this->_sock->connect();
    
    // Read banner message
    if (!($response= $this->_readResponse()))
      throw new \peer\ConnectException('No valid response from server');
      
    $this->cat && $this->cat->debug('<<<', $this->getResponse());
    if ($auth) return $this->authenticate();

    return true;
  }

  /**
   * Disconnect
   *
   * @return  bool success
   * @throws  io.IOException in case there's an error during disconnecting
   */
  public function close() {
    if (!$this->_sock->isConnected()) return true;

    $status= $this->_sendcmd('QUIT');
    if (!NntpReply::isPositiveCompletion($status)) {
      throw new \io\IOException('Error during disconnect');
    }
    $this->_sock->close();
    return true;
  }

  /**
   * Authenticate
   *
   * @param   string authmode
   * @return  bool success
   * @throws  peer.AuthenticationException in case authentication failed
   */  
  public function authenticate() {
    $status= $this->_sendcmd('AUTHINFO user', $this->url->getUser());

    // Send password if requested
    if (NNTP_AUTH_NEEDMODE === $status) {
      $status= $this->_sendcmd('AUTHINFO pass', $this->url->getPassword());
    }
    
    switch ($status) {
      case NNTP_AUTH_ACCEPT: {
        return true;
        break;
      }
      case NNTP_AUTH_NEEDMODE: {
        throw new AuthenticatorException('Authentication uncomplete');
        break;
      }
      case NNTP_AUTH_REJECTED: {
        throw new AuthenticatorException('Authentication rejected');
        break;
      }
      case NNTP_NOPERM: {
        throw new AuthenticatorException('No permission');
        break;
      }
      default: {
        throw new AuthenticatorException('Unexpected authentication error');
      }
    }
  }

  /**
   * Select a group
   *
   * @param   string groupname
   * @return  success
   */
  public function setGroup($group) {
    $status= $this->_sendcmd('GROUP', $group);
    if (!NntpReply::isPositiveCompletion($status))
      throw (new \io\IOException('Could not set group'));

    return true;
  }
  
  /**
   * Get groups
   *
   * @return  peer.news.Newsgroup[]
   */
  public function getGroups() {
    $status= $this->_sendcmd('LIST');
    if (!NntpReply::isPositiveCompletion($status))
      throw new \io\IOException('Could not get groups');

    while ($line= $this->_readData()) {
      $buf= explode(' ', $line);
      $groups[]= new Newsgroup($buf[0], (int)$buf[1], (int)$buf[2], $buf[3]);
    }

    return $groups;
  }

  /**
   * Get Article
   *
   * @param   var Id eighter a messageId or an articleId
   * @return  peer.news.Article
   * @throws  io.IOException in case article could not be retrieved
   */
  public function getArticle($id= null) {
    $status= $this->_sendcmd('ARTICLE', $id);
    if (!NntpReply::isPositiveCompletion($status)) 
      throw new \io\IOException('Could not get article');
      
    with($args= explode(' ', $this->getResponse())); {
      $article= new Article($args[0], $args[1]);
    }
    
    // retrieve headers
    while ($line= $this->_readData()) {
      if ("\t" == $line{0} || ' ' == $line{0}) {
        $article->setHeader(
          $header[0], 
          $article->getHeader($header[0])."\n".$line
        );
        continue;
      }
      $header= explode(': ', $line, 2);
      $article->setHeader($header[0], $header[1]);
    }
    
    // retrieve body
    while (false !== ($line= $this->_readData())) $body.= $line."\n";
    $article->setBody($body);
    
    return $article;
  }

  /**
   * Get a list of all articles in a newsgroup
   *
   * @return  array articleId
   * @throws  io.IOException in case article list could not be retrieved
   */
  public function getArticleList() {
    $status= $this->_sendcmd('LISTGROUP');
    if (!NntpReply::isPositiveCompletion($status)) 
      throw new \io\IOException('Could not get article list');
    
    while ($line= $this->_readData()) $articles[]= $line;
    
    return $articles;
  }
  
  /**
   * Retrieve body of an article
   *
   * @param   var Id eighter a messageId or an articleId default NULL 
   * @return  string body
   * @throws  io.IOException in case body could not be retrieved
   */
  public function getBody($id= null) {
    $status= $this->_sendcmd('BODY', $id);
    if (!NntpReply::isPositiveCompletion($status)) 
      throw new \io\IOException('Could not get article body');

    // retrieve body
    while (false !== ($line= $this->_readData())) $body.= $line."\n";
    return $body;
  }

  /**
   * Retrieve header of an article
   *
   * @param   var Id eighter a messageId or an articleId default NULL
   * @return  array headers
   * @throws  io.IOException in case headers could not be retrieved
   */
  public function getHeaders($id= null) {
    $status= $this->_sendcmd('HEAD', $id);
    if (!NntpReply::isPositiveCompletion($status)) 
      throw new \io\IOException('Could not get article headers');

    // retrieve headers
    while ($line= $this->_readData()) {
      $header= explode(': ', $line, 2);
      $headers[$header[0]]= $header[1];
    }
    
    return $headers;
  }
  
  /**
   * Retrieve next article
   *
   * @return  peer.news.Article
   * @throws  io.IOException in case article could not be retrieved
   */
  public function getNextArticle() {
    $status= $this->_sendcmd('NEXT');
    if (!NntpReply::isPositiveCompletion($status)) 
      throw new \io\IOException('Could not get next article');

    return $this->getArticle(current(explode(' ', $this->getResponse())));
  }

  /**
   * Retrieve last article
   *
   * @return  peer.news.Article
   * @throws  io.IOException in case article could not be retrieved
   */
  public function getLastArticle() {
    $status= $this->_sendcmd('LAST');
    if (!NntpReply::isPositiveCompletion($status)) 
      throw new \io\IOException('Could not get last article');

    return $this->getArticle(current(explode(' ', $this->getResponse())));
  }
  
  /**
   * Get format of xover command
   *
   * @return  array fields
   * @throws  io.IOException in case format could not be retrieved
   */    
  public function getOverviewFormat() {
    $status= $this->_sendcmd('LIST OVERVIEW.FMT');
    if (!NntpReply::isPositiveCompletion($status))
      throw new \io\IOException('Could not get overview');
      
    while ($line= $this->_readData()) {
      $fields[]= current(explode(':', $line, 2));

    }
    return $fields;
  }

  /**
   * Get a list of articles in a given range
   *
   * @param   string range default NULL
   * @return  int[] articleId
   */
  public function getOverview($range= null) {
    $status= $this->_sendcmd('XOVER', $range);
    if (!NntpReply::isPositiveCompletion($status))
      throw new \io\IOException('Could not get overview');

    while ($line= $this->_readData()) {
      $articles[]= current(explode("\t", $line, 9));
    }
    return $articles;
  }
  
  /**
   * Get all articles which are newer
   * than the given date
   *
   * @param   util.Date date
   * @param   string newsgroup
   * @return  array messageId
   */ 
  public function newNews($date, $newsgroup) {
    $status= $this->_sendcmd(
      'NEWNEWS',
      $newsgroup,
      $date->toString('ymd His')
    );
    if (!NntpReply::isPositiveCompletion($status))
      throw new \io\IOException('Could not get new articles');
      
    while ($line= $this->_readData()) $articles[]= $line;
    
    return $articles;
  }

  /**
   * Get all groups which are newer
   * than the given date
   *
   * @param   util.Date date
   * @return  array &peer.news.Newsgroup
   */
  public function newGroups($date) {
    $status= $this->_sendcmd(
      'NEWGROUPS',
      $date->toString('ymd His')
    );
    if (!NntpReply::isPositiveCompletion($status))
      throw new \io\IOException('Could not get new groups');
      
    while ($line= $this->_readData()) {
      $buf= explode(' ', $line);
      $groups[]= new Newsgroup($buf[0], (int)$buf[1], (int)$buf[2], $buf[3]);
    }

    return $groups;
  }

  /**
   * Return current response
   *
   * @return  string response
   */
  public function getResponse() {
    return $this->response[1];
  }

  /**
   * Return current statuscode
   *
   * @return  int statuscode
   */
  public function getStatus() {
    return $this->response[0];
  }

} 
