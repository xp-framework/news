<?php namespace peer\news;

/**
 * Represent a Newsgroup
 *
 * @see   xp://peer.news.NntpConnection#getGroups
 */
class Newsgroup extends \lang\Object {
  public
    $name         = '',
    $last         = null,
    $first        = null,
    $flags        = '';

  /**
   * Constructor
   *
   * @param   string $name
   * @param   int $last
   * @param   int $first
   * @param   string $flags
   */
  public function __construct($name, $last, $first, $flags) {
    $this->name= $name;
    $this->last= $last;
    $this->first= $first;
    $this->flags= $flags;
  }

  /**
   * Set Name
   *
   * @param   string $name
   */
  public function setName($name) {
    $this->name= $name;
  }

  /**
   * Get Name
   *
   * @return  string
   */
  public function getName() {
    return $this->name;
  }

  /**
   * Set Last
   *
   * @param   int $last
   */
  public function setLast($last) {
    $this->last= $last;
  }

  /**
   * Get Last
   *
   * @return  int
   */
  public function getLast() {
    return $this->last;
  }

  /**
   * Set First
   *
   * @param   int $first
   */
  public function setFirst($first) {
    $this->first= $first;
  }

  /**
   * Get First
   *
   * @return  int
   */
  public function getFirst() {
    return $this->first;
  }

  /**
   * Set Flags
   *
   * @param   string $flags
   */
  public function setFlags($flags) {
    $this->flags= $flags;
  }

  /**
   * Get Flags
   *
   * @return  string
   */
  public function getFlags() {
    return $this->flags;
  }
}
