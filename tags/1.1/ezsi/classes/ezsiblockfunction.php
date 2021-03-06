<?php
//
// Definition of eZSIBlockFunction class
//
// Created on: <24-Apr-2008 15:06:33 jr>
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ publish
// SOFTWARE RELEASE: 4.x.x
// COPYRIGHT NOTICE: Copyright (C) 1999-2009 eZ systems AS
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

class eZSiBlockFunction
{
    public function eZSiBlockFunction( $functionName = 'si-block' )
    {
        $this->FunctionName = $functionName;

        // block type : ESI or SSI
        $this->SIBlockHandler = eZSIBlockFunction::loadSIBlockHandler();

        // file handling
        $this->SIFileHandler  = eZSIBlockFunction::loadSIFileHandler();
    }

    public function functionList()
    {
        return array( $this->FunctionName );
    }

    public function functionTemplateHints()
    {
        return array( $this->FunctionName => array( 'parameters'           => true,
                                                    'static'               => false,
                                                    'transform-children'   => true,
                                                    'tree-transformation'  => false,
                                                    'transform-parameters' => true ) );
    }

    public function process( $tpl, &$textElements, $functionName, $functionChildren, $functionParameters, $functionPlacement, $rootNamespace, $currentNamespace )
    {
        switch ( $functionName )
        {
            case $this->FunctionName:
            {
                $ini                     = eZINI::instance( 'ezsi.ini' );
                $SIMarkupIsEnabled       = ( $ini->variable( 'DevelopmentSettings', 'ActivateSIMarkup' ) == 'enabled' ) ? true : false;
                $forceRegenerationString = $ini->variable( 'TemplateFunctionSettings', 'ForceRegenerationString' );
                $forceRegenerationValue  = $ini->variable( 'TemplateFunctionSettings', 'ForceRegenerationValue' );
                $SIFileHandler           = strtolower( $ini->variable( 'SIFilesSettings', 'FileHandler' ) );
                $SIBlockHandler          = strtolower( $ini->variable( 'SIBlockSettings', 'BlockHandler' ) );

                // check for incompatible behavior, basically SSI + FTP
                if( $SIFileHandler == 'ftp' and $SIBlockHandler == 'ssi' )
                {
                    eZDebug::writeError( 'Incompatible configuration, SSI + FTP is not allowed', 'eZSIBlockFunction::process' );
                    break;
                }

                $regenerationIsForced = false;

                $http = eZHTTPTool::instance();
                if( $http->hasGetVariable( $forceRegenerationString ) and $http->getVariable( $forceRegenerationString ) == $forceRegenerationValue )
                {
                    $regenerationIsForced = true;
                }

                $blockFilePathPreprendString = $ini->variable( 'SIBlockSettings', 'BlockFilePathPrependString' );

                if( $this->keyIsValid( $tpl,
                                       $rootNamespace,
                                       $currentNamespace,
                                       $functionParameters,
                                       $functionPlacement )
                    and
                    $this->ttlIsValid( $tpl,
                                       $rootNamespace,
                                       $currentNamespace,
                                       $functionParameters,
                                       $functionPlacement ) )
                {
                    $blockKeyString = $this->generateBlockKeyString( $tpl, $rootNamespace, $currentNamespace, $functionParameters, $functionPlacement );

                    eZDebug::writeNotice( $blockKeyString, 'eZSIBlockFunction::process' );

                    // generating filepath
                    $cacheDir = $this->cacheBaseSubdir();
                    $fileName = $this->generateUniqueFilename( $blockKeyString );

                    if( $SIFileHandler == 'fs' )
                    {
                        $blockFilePathPreprendString = 'var/';
                    }

                    $filePath = $blockFilePathPreprendString . $cacheDir . '/' . $fileName;

                    eZDebug::writeNotice( $filePath, 'eZSIBlockFunction::process' );

                    if( $SIBlockHandler == 'ssi' )
                    {
                        $this->SIBlockHandler->Src = './' . $filePath;
                    }
                    else
                    {
                        $this->SIBlockHandler->Src = $filePath;
                    }

                    // file exists ?
                    if( $fileInfo = $this->fileExists( $filePath ) )
                    {
                        eZDebug::writeNotice( 'file exists : ' . $blockKeyString, 'eZSIBlockFunction::process' );

                        // does the ttl needs to be updated ?
                        $blockTTL = $tpl->elementValue( $functionParameters['ttl'], $rootNamespace, $currentNamespace, $functionPlacement );
                        if( !$this->updateTTLIfNeeded( $fileInfo[0]['ttl'], md5( $filePath ), $blockTTL ) )
                        {
                            eZDebug::writeError( 'si-blocks : unable to update the TTL for file : ' . $filePath );
                        }

                        // expired ?
                        if( $this->SIBlockHandler->fileIsExpired( $fileInfo[0]['mtime'] ) or $regenerationIsForced )
                        {
                            eZDebug::writeNotice( 'file expired : ' . $blockKeyString, 'eZSIBlockFunction::process' );

                            $htmlContents = $this->processChildren( $tpl, $functionChildren, $rootNamespace, $currentNamespace );

                            $db = eZDB::instance();
                            $db->begin();

                            // update file
                            if( $this->SIFileHandler->storeFile( $cacheDir, $fileName, $htmlContents ) )
                            {
                                // update table
                                if( !$this->updateRow( $filePath, $this->SIBlockHandler->TTLInSeconds() ) )
                                {
                                    $this->SIFileHandler->removeFile( $cacheDir, $fileName );
                                    eZDebug::writeError( $filePath . ' could not be updated', 'eZSIBlockFunction::process' );
                                    $db->rollback();
                                }
                                else
                                {
                                    // yes I can commit the transaction here
                                    $db->commit();

                                    if( $SIMarkupIsEnabled )
                                    {
                                        $textElements[] = $this->SIBlockHandler->generateMarkup();
                                    }
                                    else
                                    {
                                        $textElements[] = $htmlContents;
                                    }
                                }
                            }
                            else
                            {
                                eZDebug::writeError( 'Unable to store the file : ' . $filePath, 'eZSIBlockFunction::process' );
                            }
                        }
                        else
                        {
                            eZDebug::writeNotice( 'file valid : ' . $blockKeyString, 'eZSIBlockFunction::process' );

                            if( $SIMarkupIsEnabled )
                            {
                                $textElements[] = $this->SIBlockHandler->generateMarkup();
                            }
                            else
                            {
                                $textElements[] = $this->processChildren( $tpl, $functionChildren, $rootNamespace, $currentNamespace );
                            }
                        }
                    }
                    else
                    {
                        eZDebug::writeNotice( 'file does not exists : ' . $blockKeyString, 'eZSIBlockFunction::process' );

                        $htmlContents = $this->processChildren( $tpl, $functionChildren, $rootNamespace, $currentNamespace );

                        $db = eZDB::instance();
                        $db->begin();

                        // create file
                        if( $this->SIFileHandler->storeFile( $cacheDir, $fileName, $htmlContents ) )
                        {
                            // insert into table
                            if( !$this->writeRow( $filePath, $this->SIBlockHandler->TTLInSeconds(), $blockKeyString ) )
                            {
                                $this->SIFileHandler->removeFile( $cacheDir, $fileName );
                                eZDebug::writeError( $filePath . ' could not be created', 'eZSIBlockFunction::process' );
                                $db->rollback();
                            }
                            else
                            {
                                // yes I can commit the transaction here
                                $db->commit();

                                if( $SIMarkupIsEnabled )
                                {
                                    $textElements[] = $this->SIBlockHandler->generateMarkup();
                                }
                                else
                                {
                                    $textElements[] = $htmlContents;
                                }
                            }
                        }
                        else
                        {
                            eZDebug::writeError( 'Unable to store the file : ' . $filePath, 'eZSIBlockFunction::process' );
                        }
                    }
                }
            } break;
        }
    }

    private function generateBlockKeyString( $tpl, $rootNamespace, $currentNamespace, $functionParameters, $functionPlacement )
    {
        $viewParametersString = $this->generateViewParametersString( '', '_' );

        $elementValue = $tpl->elementValue( $functionParameters['key'], $rootNamespace, $currentNamespace, $functionPlacement );

        if( is_array( $elementValue ) )
        {
            $elementValue = join( '_', $elementValue );
        }

        // values of the "key" attribute
        // converted into a string
        $blockKeyArray[] = $elementValue;

        // line number of the {si-block call}
        $blockKeyArray[] = $functionPlacement[0][0];

        $blockKeyArray[] = $functionPlacement[1][0];

        // template's filepath
        $blockKeyArray[] = $functionPlacement[2];

        // view_parameters
        $blockKeyArray[] = $viewParametersString;

        // fetching the current siteaccess
        $accessName = $GLOBALS['eZCurrentAccess']['name'];

        $blockKeyArray[] = $accessName;

        $blockKeyString = join( '_', $blockKeyArray );

        return $blockKeyString;
    }

    public function processChildren( $tpl, $functionChildren, $rootNamespace, $currentNamespace )
    {
        // generating HTML
        $childTextElements = array();
        foreach ( array_keys( $functionChildren ) as $childKey )
        {
            $child = $functionChildren[ $childKey ] ;
            $tpl->processNode( $child, $childTextElements, $rootNamespace, $currentNamespace );
        }
        $text = join( '', $childTextElements );

        eZDebug::writeNotice( $text, 'eZSIBlockFunction::process' );
        return $text;
    }

    public function hasChildren()
    {
        return true;
    }

    public function keyIsValid( $tpl, $rootNamespace, $currentNamespace, $functionParameters, $functionPlacement )
    {
        $keyString = $tpl->elementValue( $functionParameters['key'], $rootNamespace, $currentNamespace, $functionPlacement );

        $this->SIBlockHandler->setKey( $keyString );

        if( !$this->SIBlockHandler->validateKey() )
        {
            $tpl->error( $this->FunctionName, 'si-block : The key is not valid', $functionPlacement );

            return false;
        }

        return true;
    }

    public function ttlIsValid( $tpl, $rootNamespace, $currentNamespace, $functionParameters, $functionPlacement )
    {
        $ttlString = $tpl->elementValue( $functionParameters['ttl'], $rootNamespace, $currentNamespace, $functionPlacement );

        $this->SIBlockHandler->setTTL( $ttlString );

        if( !$this->SIBlockHandler->validateTTL() )
        {
            $tpl->error( $this->FunctionName, 'si-block : The TTL is not valid', $functionPlacement );

            return false;
        }

        return true;
    }

    private function generateUniqueFilename( $SIBlockKey )
    {
        $uniqueFilename = md5( $SIBlockKey  );

        eZDebug::writeNotice( $uniqueFilename, 'eZSIBlockFunction::generateUniqueFilename' );

        return $uniqueFilename.'.htm';
    }

    private function fileExists( $filePath )
    {
        $db   = eZDB::instance();
        $sql  = 'SELECT ttl, mtime FROM ezsi_files WHERE namehash = "' . md5( $filePath ) . '"';
        $rows = $db->arrayQuery( $sql );

        if( count( $rows ) == 1 )
        {
            return $rows;
        }

        return false;
    }

    private function writeRow( $filePath, $TTLInSeconds, $blockKeyString )
    {
        $viewParametersString = $this->generateViewParametersString( '/(', ')/' );

        $eZURI = eZURI::instance( eZSys::requestURI() );
        $urlAlias = $eZURI->URIString() . $viewParametersString;

        // fetching the current siteaccess
        $accessName = $GLOBALS['eZCurrentAccess']['name'];

        $db = eZDB::instance();
        $sql = 'INSERT INTO ezsi_files ( filepath, namehash, mtime, urlalias, siteaccess, ttl, blockkeys )
                VALUES( "' . $db->escapeString( trim( $filePath ) )        . '", "'
                           . $db->escapeString( md5( trim( $filePath ) ) ) . '", "'
                           . time()                                        . '", "'
                           . $db->escapeString( trim( $urlAlias ) )        . '", "'
                           . $db->escapeString( trim( $accessName ) )      . '", "'
                           . $db->escapeString( trim( $TTLInSeconds ) )    . '", "'
                           . $db->escapeString( trim( $blockKeyString ) )  . '" )';

        eZDebug::writeNotice( $sql, 'Creating rows' );

        return $db->query( $sql );
    }

    private function updateRow( $filePath, $TTLInSeconds )
    {
        $db = eZDB::instance();
        $sql = 'UPDATE ezsi_files SET mtime = ' . time() . ', ttl = ' . $TTLInSeconds . '
                WHERE namehash = "'. $db->escapeString( md5( $filePath ) ).'"';

        eZDebug::writeNotice( $sql, 'Updating rows' );

        return $db->query( $sql );

    }

    public function cacheBaseSubdir()
    {
        return 'si-blocks';
    }

    private function generateViewParametersString( $preSeparator = '', $postSeparator = '' )
    {
        // taking view parameters and generate a string with them
        $eZURI = eZURI::instance( eZSys::requestURI() );
        $viewParametersString = '';

        foreach( $eZURI->UserArray as $paramName => $paramValue )
        {
            $viewParametersString .= $preSeparator . $paramName . $postSeparator . $paramValue;
        }

        return $viewParametersString;
    }

    public static function loadSIBlockHandler()
    {
        $ini                    = eZINI::instance( 'ezsi.ini' );
        $SIBlockHandlerName     = strtolower( $ini->variable( 'SIBlockSettings', 'BlockHandler' ) );
        $SIBlockHandlerFilePath = 'extension/ezsi/classes/blockhandlers/'
                                  . $SIBlockHandlerName
                                  . '/ezsi'
                                  . $SIBlockHandlerName
                                  . 'blockhandler.php';

        if( file_exists( $SIBlockHandlerFilePath ) )
        {
            eZDebug::writeNotice( 'Loading ' . $SIBlockHandlerFilePath, 'eZSIBlockFunction::loadSIBlockHandler' );
            include_once( $SIBlockHandlerFilePath );
            $SIBlockHandlerClassName = 'ezsi' . $SIBlockHandlerName . 'blockHandler';
            return new $SIBlockHandlerClassName();
        }
        else
        {
            eZDebug::writeError( $SIBlockHandlerFilePath . ' does not exists ', 'eZSI::loadSIBlockHandler' );
        }

        return false;
    }

    public static function loadSIFileHandler()
    {
        $ini                   = eZINI::instance( 'ezsi.ini' );
        $SIFileHandlerName     = strtolower( $ini->variable( 'SIFilesSettings', 'FileHandler' ) );
        $SIFileHandlerFilePath = 'extension/ezsi/classes/filehandlers/'
                                 . $SIFileHandlerName
                                 . '/ezsi'
                                 . $SIFileHandlerName
                                 . 'filehandler.php';

        if( file_exists( $SIFileHandlerFilePath ) )
        {
            eZDebug::writeNotice( 'Loading ' . $SIFileHandlerFilePath, 'eZSIBlockFunction::loadSIFileHandler' );
            include_once( $SIFileHandlerFilePath );
            $SIFileHandlerClassName = 'ezsi' . $SIFileHandlerName . 'filehandler';
            return call_user_func( array( $SIFileHandlerClassName, 'instance' ) );
        }
        else
        {
            eZDebug::writeError( $SIFileHandlerFilePath . ' does not exists ', 'eZSI::loadSIFileHandler' );
        }

        return false;
    }

    private function updateTTLIfNeeded( $storedTTL, $nameHash, $blockTTL )
    {
        $ttlInfo = $this->SIBlockHandler->TTLInSeconds();

        if( $storedTTL != $ttlInfo )
        {
            eZDebug::writeNotice( 'The ttl needs to be updated', 'eZSIBlockFunction::updateTTLIfNeeded' );

            $db  = eZDB::instance();
            $sql = 'UPDATE ezsi_files SET ttl = ' . $ttlInfo . ' WHERE namehash = "' . $db->escapeString( $nameHash ) . '"';

            eZDebug::writeNotice( $sql, 'eZSIBlockFunction::updateTTLIfNeeded' );

            // update the TTL in the current process
            // makes it possible to update the cache file
            // as well if necessary
            if( $db->query( $sql ) )
            {
                $this->SIBlockHandler->setTTL( $blockTTL );

                return true;
            }

            return false;
        }

        return true;
    }

    // Name of the function
    private $FunctionName;

    // Name of the SI block handler : ESI or SSI
    private $SIBlockHandler;

    // Name of the file handler : FS or FTP so far
    private $SIFileHandler;
}

?>