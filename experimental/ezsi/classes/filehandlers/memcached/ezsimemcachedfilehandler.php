<?php

class eZSIMemcachedFileHandler extends eZSIFileHandler
{
    private function eZSIMemcachedFileHandler()
    {
        if( !extension_loaded( 'memcache' ) )
        {
            eZDebug::writeError( 'The memcached file handler require pecl_memcached, see http://pecl.php.net/memcache' );
            return false;
        }
    }

    public static function instance()
    {
        if( isset( $GLOBALS['eZSIMemcachedFileHandler'] ) and is_object( $GLOBALS['eZSIMemcachedFileHandler'] ) )
        {
            return $GLOBALS['eZSIMemcachedFileHandler'];
        }
        else
        {
            return new eZSIMemcachedFileHandler();
        }
    }

    private function connect()
    {
        if( is_resource( $this->ConnectionResource ) )
        {
            eZDebug::writeError( 'No Connexion Resource available', 'eZSIMemcachedFileHandler::connect' );
            return false;
        }

        $ini               = eZINI::instance( 'memcached.ini' );
        $host              = $ini->variable( 'MemcachedSettings', 'Host' );
        $port              = $ini->variable( 'MemcachedSettings', 'Port' );
        $timeout           = $ini->variable( 'MemcachedSettings', 'TimeOut' );

        if( $cr = memcache_connect( $host, $port, $timeout ) )
        {
            eZDebug::writeNotice( 'Connecting to Memcached server', 'eZSIMemcachedFileHandler' );

            $this->ConnectionResource = $cr;
            $GLOBALS['eZSIMemcachedFileHandler'] = $this;
            unset( $cr );

            // make sure the connexion is closed at the end of the script
            eZExecution::addCleanupHandler( 'eZSIMemcachedCloseConnexion' );

            return true;
        }
        else
        {
            eZDebug::writeError( 'Unable to connect to Memcached server', 'eZSIMemcachedFileHandler' );

            return false;
        }
    }

    public function storeFile( $directory, $fileName, $fileContents )
    {
        if( !self::connect() )
        {
            return false;
        }

        $key = $directory . '/' . $fileName;
        // $flag = MEMCACHE_COMPRESSED;
        $flag = false;

        //the file does not exists
        if( !memcache_replace( $this->ConnectionResource, $key, $fileContents, $flag, 0 ) )
        {
            if( !memcache_add( $this->ConnectionResource, $key, $fileContents, $flag, 0 ) )
            {
                eZDebug::writeError( 'Unable to store file', 'eZSIMemcachedFileHandler::storeFile' );
                return false;
            }

            return false;
        }

        return true;
    }

    public function removeFile( $directory, $fileName )
    {
        if( !self::connect() )
        {
            return false;
        }

        $key = $directory . '/' . $fileName;

        if( !memcache_delete( $this->ConnectionResource, $key ) )
        {
            eZDebug::writeError( 'Unable to delete file', 'eZSIMemcachedFileHandler::removeFile' );
            return false;
        }

        return true;
    }

    public function close()
    {
        return memcache_close( $this->ConnectionResource );
    }

    var $ConnectionResource = false;
}

function eZSIMemcachedCloseConnexion()
{
    $eZSIMemcached = eZSIMemcachedFileHandler::instance();
    $eZSIMemcached->close();
}
?>

