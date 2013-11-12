NNTP protocol support for the XP Framework
========================================================================

Usage (retrieve newsgroup listing):

```php
use peer\news\NntpConnection;
use util\cmd\Console;

$c= new NntpConnection('nntp://news.xp-framework.net');
$c->connect();
foreach ($c->getGroups() as $group) {
  Console::writeLine($group->getName());
}

$c->close();
```