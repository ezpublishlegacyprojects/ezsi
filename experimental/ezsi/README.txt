This folder contains the required files to user Memcached as a file handler
for ezsi.

In order to be able to use them in the standard ezsi distribution just copy/paste
the files in the extension and in settings/ezsi.ini.append.php set FileHandler=Memcached

The memcached.ini.append.php file stores the configuration for memcached

This experimental part has to be used with a custom Apache module which 
makes it possible to fetch cache file from memcached :

http://code.google.com/mod_memcached_include
