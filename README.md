# PHP Partial Zip

PHP Partial Zip allows you to download files located within remote ZIP files.

Based on [planetbeing/partial-zip](https://github.com/planetbeing/partial-zip).

#### Usage:

```composer require stnvh/php-partialzip 0.2.x```

##### Method usage:

###### __construct($url, $file = false):
Class init method
```php
$p = new Partial('http://some.site.com/cats.zip');
```

###### index():
Returns a list of all the files in the remote directory
```php
/*...*/

$list = $p->index(); # = ('cat.png', 'cat2.png', 'cat3.png')
```

###### find($fileName = false):
Returns a parsed file object for use when fetching the remote file
```php
/*...*/

# Search and return other file objects
if($file = $p->find('cat2.png')) {
	# You can call methods here to fetch ZIP header information too
	# The full list of file header properties can be found in CDFile.php
	$size = $file->size(); # size in bytes
	$fullName = $file->name(); # full file name in zip, including path
}

```

###### get($file):
Returns, or outputs the file fetched from the remote ZIP.

**Note**: You should ensure no content is outputted before echo-ing ```->get()``` as this will cause the file download to contain invalid data.
*Hint*: put ```ob_start()``` at the start of your script, then run ```ob_clean()``` before output.
```php
/*...*/

if($file = $p->find('cat3.png')) {
    $fileData = $p->get($file);
}
```

##### example:

```php
<?php

require 'vendor/autoload.php';

use Stnvh\Partial\Zip as Partial;

ob_start(); # will capture all output

$p = new Partial('http://some.site.com/cats.zip');

# Get file object
if($file = $p->find('cat.png')) {
	# removes everything from current output to ensure file downloads correctly
    ob_clean();

    # Set appropriate headers and output to browser:
	header(sprintf('Content-Disposition: attachment; filename="%s"', $file->filename));
	header(sprintf('Content-Length: %d', $file->size));

    echo $p->get($file);
}
```
