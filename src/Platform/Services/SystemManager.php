<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
 * Copyright 2012-2013 DreamFactory Software, Inc. <developer-support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace Platform\Services;

use Kisma\Core\Utility\Log;
use Kisma\Core\Utility\Option;
use Kisma\Core\Utility\Sql;
use Kisma\Core\Interfaces\HttpResponse;
use Platform\Exceptions\BadRequestException;
use Platform\Exceptions\InternalServerErrorException;
use Platform\Interfaces\PlatformStates;
use Platform\Resources\SystemApp;
use Platform\Resources\SystemAppGroup;
use Platform\Resources\SystemConfig;
use Platform\Resources\SystemEmailTemplate;
use Platform\Resources\SystemRole;
use Platform\Resources\SystemService;
use Platform\Resources\SystemUser;
use Platform\Utility\Curl;
use Platform\Utility\DataFormat;
use Platform\Utility\FileUtilities;
use Platform\Utility\SqlDbUtilities;
use Platform\Utility\Utilities;
use Platform\Yii\Utility\Pii;
use Swagger\Annotations as SWG;

/**
 * SystemManager
 * DSP system administration manager
 *
 * @SWG\Resource(
 *   resourcePath="/system"
 * )
 *
 */
class SystemManager extends RestService
{
	//*************************************************************************
	//	Constants
	//*************************************************************************

	/**
	 * @var string
	 */
	const SYSTEM_TABLE_PREFIX = 'df_sys_';
	/**
	 * @var string The private CORS configuration file
	 */
	const CORS_DEFAULT_CONFIG_FILE = '/cors.config.json';


	//*************************************************************************
	//	Members
	//*************************************************************************

	//*************************************************************************
	//	Methods
	//*************************************************************************

	/**
	 * Creates a new SystemManager instance
	 *
	 */
	public function __construct()
	{
		$config = array(
			'name'        => 'System Configuration Management',
			'api_name'    => 'system',
			'type'        => 'System',
			'description' => 'Service for system administration.',
			'is_active'   => true,
		);

		parent::__construct( $config );
	}

	// Service interface implementation

	/**
	 * @param string $apiName
	 *
	 * @return \Platform\Services\BaseService|void
	 * @throws \Exception
	 */
	public function setApiName( $apiName )
	{
		throw new \Exception( 'SystemManager API name can not be changed.' );
	}

	/**
	 * @param string $type
	 *
	 * @return \Platform\Services\BaseService|void
	 * @throws \Exception
	 */
	public function setType( $type )
	{
		throw new \Exception( 'SystemManager type can not be changed.' );
	}

	/**
	 * @param string $description
	 *
	 * @return \Platform\Services\BaseService|void
	 * @throws \Exception
	 */
	public function setDescription( $description )
	{
		throw new \Exception( 'SystemManager description can not be changed.' );
	}

	/**
	 * @param boolean $isActive
	 *
	 * @return \Platform\Services\BaseService|void
	 * @throws \Exception
	 */
	public function setIsActive( $isActive )
	{
		throw new \Exception( 'SystemManager active flag can not be changed.' );
	}

	/**
	 * @param string $name
	 *
	 * @return \Platform\Services\BaseService|void
	 * @throws \Exception
	 */
	public function setName( $name )
	{
		throw new \Exception( 'SystemManager name can not be changed.' );
	}

	/**
	 * @param string $nativeFormat
	 *
	 * @return \Platform\Services\BaseService|void
	 * @throws \Exception
	 */
	public function setNativeFormat( $nativeFormat )
	{
		throw new \Exception( 'SystemManager native format can not be changed.' );
	}

	/**
	 * @param string $old
	 * @param string $new
	 * @param bool   $useVersionCompare If true, built-in "version_compare" will be used
	 * @param null   $operator          Operator to pass to version_compare
	 *
	 * @return bool|mixed
	 */
	public static function doesDbVersionRequireUpgrade( $old, $new, $useVersionCompare = false, $operator = null )
	{
		if ( false !== $useVersionCompare )
		{
			return version_compare( $old, $new, $operator );
		}

		return ( 0 !== strcasecmp( $old, $new ) );
	}

	/**
	 * Determines the current state of the system
	 */
	public static function getSystemState()
	{
		// Refresh the schema that we just added
		$_db = Pii::db();
		$_schema = $_db->getSchema();

		$tables = $_schema->getTableNames();

		if ( empty( $tables ) || ( 'df_sys_cache' == Utilities::getArrayValue( 0, $tables ) ) )
		{
			return PlatformStates::INIT_REQUIRED;
		}

		// need to check for db upgrade, based on tables or version
		$contents = file_get_contents( Pii::basePath() . '/data/system_schema.json' );

		if ( !empty( $contents ) )
		{
			$contents = DataFormat::jsonToArray( $contents );

			// check for any missing necessary tables
			$needed = Utilities::getArrayValue( 'table', $contents, array() );

			foreach ( $needed as $table )
			{
				$name = Utilities::getArrayValue( 'name', $table, '' );
				if ( !empty( $name ) && !in_array( $name, $tables ) )
				{
					return PlatformStates::SCHEMA_REQUIRED;
				}
			}

			$version = Utilities::getArrayValue( 'version', $contents );
			$oldVersion = $_db->createCommand()
						  ->select( 'db_version' )->from( 'df_sys_config' )
						  ->order( 'id DESC' )->limit( 1 )
						  ->queryScalar();
			if ( static::doesDbVersionRequireUpgrade( $oldVersion, $version ) )
			{
				return PlatformStates::SCHEMA_REQUIRED;
			}
		}

		// Check for at least one system admin user
		$command = $_db->createCommand()
				   ->select( '(COUNT(*))' )->from( 'df_sys_user' )->where( 'is_sys_admin=:is' );
		if ( 0 == $command->queryScalar( array( ':is' => 1 ) ) )
		{
			return PlatformStates::ADMIN_REQUIRED;
		}

		// Need to check for the default services
		$command = $_db->createCommand()
				   ->select( '(COUNT(*))' )->from( 'df_sys_service' );
		if ( 0 == $command->queryScalar() )
		{
			return PlatformStates::DATA_REQUIRED;
		}

		return PlatformStates::READY;
	}

	/**
	 * Configures the system.
	 *
	 * @return null
	 */
	public static function initSystem()
	{
		static::initSchema( true );
		static::initAdmin();
		static::initData();
	}

	/**
	 * Configures the system schema.
	 *
	 * @param bool $init
	 *
	 * @throws \Exception
	 * @return null
	 */
	public static function initSchema( $init = false )
	{
		$_db = Pii::db();

		try
		{
			$contents = file_get_contents( Pii::basePath() . '/data/system_schema.json' );

			if ( empty( $contents ) )
			{
				throw new \Exception( "Empty or no system schema file found." );
			}

			$contents = DataFormat::jsonToArray( $contents );
			$version = Utilities::getArrayValue( 'version', $contents );

			$command = $_db->createCommand();
			$oldVersion = '';
			if ( SqlDbUtilities::doesTableExist( $_db, static::SYSTEM_TABLE_PREFIX . 'config' ) )
			{
				$command->reset();
				$oldVersion = $command->select( 'db_version' )->from( 'df_sys_config' )->queryScalar();
			}

			// create system tables
			$tables = Utilities::getArrayValue( 'table', $contents );
			if ( empty( $tables ) )
			{
				throw new \Exception( "No default system schema found." );
			}

			$result = SqlDbUtilities::createTables( $_db, $tables, true, false );

			if ( !empty( $oldVersion ) )
			{
				// clean up old unique index, temporary for upgrade
				try
				{
					$command->reset();
					$command->dropIndex( 'undx_df_sys_user_username', 'df_sys_user' );
					$command->dropindex( 'ndx_df_sys_user_email', 'df_sys_user' );
				}
				catch ( \Exception $_ex )
				{
					Log::error( 'Exception clearing username index: ' . $_ex->getMessage() );
				}
			}
			// initialize config table if not already
			try
			{
				$command->reset();
				if ( empty( $oldVersion ) )
				{
					// first time is troublesome with session user id
					$rows = $command->insert( 'df_sys_config', array( 'db_version' => $version ) );
				}
				else
				{
					$rows = $command->update( 'df_sys_config', array( 'db_version' => $version ) );
				}
				if ( 0 >= $rows )
				{
					throw new \Exception( "old_version: $oldVersion new_version: $version" );
				}
			}
			catch ( \Exception $_ex )
			{
				Log::error( 'Exception saving database version: ' . $_ex->getMessage() );
			}

			//	Refresh the schema that we just added
			$_db->getSchema()->refresh();
		}
		catch ( \Exception $ex )
		{
			throw $ex;
		}

		if ( !$init )
		{
			// clean up session
			static::initAdmin();
		}
	}

	/**
	 * Configures the system.
	 *
	 * @throws \Exception
	 * @return null
	 */
	public static function initAdmin()
	{
		/** @var \CWebUser $_user  */
		$_user = \Yii::app()->user;
		$_piiUser = Pii::app()->getUser();

		try
		{
			// Create and login first admin user
			$email = $_user->getState( 'email' );
			$pwd = $_user->getState( 'password' );

			if ( empty( $email ) || empty( $pwd ) )
			{
				Pii::redirect( '/site/login' );
			}

			$theUser = \User::model()->find( 'email = :email', array( ':email' => $email ) );

			if ( empty( $theUser ) )
			{
				$theUser = new \User();
				$firstName = $_user->getState( 'first_name' );
				$lastName = $_user->getState( 'last_name' );
				$displayName = $_user->getState( 'display_name' );
				$displayName = ( empty( $displayName )
					? $firstName . ( empty( $lastName ) ? '' : ' ' . $lastName )
					: $displayName );
				$fields = array(
					'email'        => $email,
					'password'     => $pwd,
					'first_name'   => $firstName,
					'last_name'    => $lastName,
					'display_name' => $displayName,
					'is_active'    => true,
					'is_sys_admin' => true,
					'confirm_code' => 'y'
				);
			}
			else
			{
				// in case something is messed up
				$fields = array(
					'is_active'    => true,
					'is_sys_admin' => true,
					'confirm_code' => 'y'
				);
			}

			$theUser->setAttributes( $fields );

			// write back login datetime
			$theUser->last_login_date = date( 'c' );
			$theUser->save();

			// update session with current real user
			$_user->setId( $theUser->primaryKey );
			$_user->setState( 'df_authenticated', false ); // removes catch
			$_user->setState( 'password', $pwd, $pwd ); // removes password
		}
		catch ( \Exception $ex )
		{
			throw new BadRequestException( "Failed to create a new user.\n{$ex->getMessage()}" );
		}
	}

	/**
	 * Configures the default system data.
	 *
	 * @throws \Platform\Exceptions\InternalServerErrorException
	 * @throws \Exception
	 * @return boolean whether configuration is successful
	 */
	public static function initData()
	{
		// init with system required data
		$contents = file_get_contents( Pii::basePath() . '/data/system_data.json' );
		if ( empty( $contents ) )
		{
			throw new \Exception( "Empty or no system data file found." );
		}
		$contents = DataFormat::jsonToArray( $contents );
		foreach ( $contents as $table => $content )
		{
			switch ( $table )
			{
				case 'df_sys_service':
					$result = \Service::model()->findAll();
					if ( empty( $result ) )
					{
						if ( empty( $content ) )
						{
							throw new \Exception( "No default system services found." );
						}
						foreach ( $content as $service )
						{
							try
							{
								$obj = new \Service;
								$obj->setAttributes( $service );
								$obj->save();
							}
							catch ( \Exception $ex )
							{
								throw new InternalServerErrorException( "Failed to create services.\n{$ex->getMessage()}" );
							}
						}
					}
					break;
			}
		}
		// init system with sample setup
		$contents = file_get_contents( Pii::basePath() . '/data/sample_data.json' );
		if ( !empty( $contents ) )
		{
			$contents = DataFormat::jsonToArray( $contents );
			foreach ( $contents as $table => $content )
			{
				switch ( $table )
				{
					case 'df_sys_service':
						if ( !empty( $content ) )
						{
							foreach ( $content as $service )
							{
								try
								{
									$obj = new \Service;
									$obj->setAttributes( $service );
									$obj->save();
								}
								catch ( \Exception $ex )
								{
									Log::error( "Failed to create sample services.\n{$ex->getMessage()}" );
								}
							}
						}
						break;
					case 'app_package':
						$result = \App::model()->findAll();
						if ( empty( $result ) )
						{
							if ( !empty( $content ) )
							{
								foreach ( $content as $package )
								{
									$fileUrl = Utilities::getArrayValue( 'url', $package, '' );
									if ( 0 === strcasecmp( 'dfpkg', FileUtilities::getFileExtension( $fileUrl ) ) )
									{
										try
										{
											// need to download and extract zip file and move contents to storage
											$filename = FileUtilities::importUrlFileToTemp( $fileUrl );
											SystemApp::importAppFromPackage( $filename, $fileUrl );
										}
										catch ( \Exception $ex )
										{
											Log::error( "Failed to import application package $fileUrl.\n{$ex->getMessage()}" );
										}
									}
								}
							}
						}
						break;
				}
			}
		}
	}

	/**
	 * Upgrades the DSP code base and runs the installer.
	 *
	 * @param string $version Version to upgrade to, should be a github tag identifier
	 *
	 * @throws \Exception
	 * @return void
	 */
	public static function upgradeDsp( $version )
	{
		if ( \Fabric::fabricHosted() )
		{
			throw new \Exception( 'Fabric hosted DSPs can not be upgraded.' );
		}

		if ( empty( $version ) )
		{
			throw new \Exception( 'No version information in upgrade load.' );
		}
		$_versionUrl = 'https://github.com/dreamfactorysoftware/dsp-core/archive/' . $version . '.zip';

		// copy current directory to backup
		$_upgradeDir = Pii::getParam( 'base_path' ) . '/';
		$_backupDir = Pii::getParam( 'storage_base_path' ) . '/backups/';
		if ( !file_exists( $_backupDir ) )
		{
			@\mkdir( $_backupDir, 0777, true );
		}
		$_backupZipFile = $_backupDir . 'dsp_' . Pii::getParam( 'dsp.version' ) . '-' . time() . '.zip';
		$_backupZip = new \ZipArchive();
		if ( true !== $_backupZip->open( $_backupZipFile, \ZIPARCHIVE::CREATE ) )
		{
			throw new \Exception( 'Error opening zip file.' );
		}
		$_skip = array( '.', '..', '.git', '.idea', 'log', 'vendor', 'shared', 'storage' );
		try
		{
			FileUtilities::addTreeToZip( $_backupZip, $_upgradeDir, '', $_skip );
		}
		catch ( \Exception $ex )
		{
			throw new \Exception( "Error zipping contents to backup file - $_backupDir\n.{$ex->getMessage()}" );
		}
		if ( !$_backupZip->close() )
		{
			throw new \Exception( "Error writing backup file - $_backupZipFile." );
		}

		// need to download and extract zip file of latest version
		$_tempDir = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		try
		{
			$_tempZip = FileUtilities::importUrlFileToTemp( $_versionUrl );
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $_tempZip ) )
			{
				throw new \Exception( 'Error opening zip file.' );
			}
			if ( !$zip->extractTo( $_tempDir ) )
			{
				throw new \Exception( "Error extracting zip contents to temp directory - $_tempDir." );
			}
		}
		catch ( \Exception $ex )
		{
			throw new \Exception( "Failed to import dsp package $_versionUrl.\n{$ex->getMessage()}" );
		}

		// now copy over
		$_tempDir .= 'dsp-core-' . $version;
		if ( !file_exists( $_tempDir ) )
		{
			throw new \Exception( "Failed to find new dsp package $_tempDir." );
		}
		// blindly, or are there things we shouldn't touch here?
		FileUtilities::copyTree( $_tempDir, $_upgradeDir, false, $_skip );

		// now run installer script
		$_oldWorkingDir = getcwd();
		chdir( $_upgradeDir );
		$_installCommand = 'scripts/installer.sh -cv';
		exec( $_installCommand, $_installOut, $_status );

		// back to normal
		chdir( $_oldWorkingDir );
	}

	public static function getDspVersions()
	{
		$_results = Curl::get(
			'https://api.github.com/repos/dreamfactorysoftware/dsp-core/tags',
			array(),
			array( CURLOPT_HTTPHEADER => array( 'User-Agent: dreamfactory' ) )
		);

		if ( HttpResponse::Ok != Curl::getLastHttpCode() )
		{
			// log an error here, but don't stop config pull
			return '';
		}

		if ( !empty( $_results ) )
		{
			return json_decode( $_results, true );
		}

		return array();
	}

	public static function getLatestVersion()
	{
		$_versions = static::getDspVersions();

		if ( isset($_versions[0] ) )
		{
			return Option::get( $_versions[0], 'name', '' );
		}

		return '';
	}

	public static function getCurrentVersion()
	{

		return Pii::getParam( 'dsp.version' );
	}

	public static function getAllowedHosts()
	{
		$_allowedHosts = array();
		$_file = Pii::getParam( 'storage_base_path' ) . static::CORS_DEFAULT_CONFIG_FILE;
		if ( !file_exists( $_file ) )
		{
			// old location
			$_file = Pii::getParam( 'private_path' ) . static::CORS_DEFAULT_CONFIG_FILE;
		}
		if ( file_exists( $_file ) )
		{
			$_content = file_get_contents( $_file );
			if ( !empty( $_content ) )
			{
				$_allowedHosts = json_decode( $_content, true );
			}
		}

		return $_allowedHosts;
	}

	public static function setAllowedHosts( $allowed_hosts = array() )
	{
		static::validateHosts( $allowed_hosts );
		$allowed_hosts = DataFormat::jsonEncode( $allowed_hosts, true );
		$_path = Pii::getParam( 'storage_base_path' );
		$_config = $_path . static::CORS_DEFAULT_CONFIG_FILE;
		// create directory if it doesn't exists
		if ( !file_exists( $_path ) )
		{
			@\mkdir( $_path, 0777, true );
		}
		// write new cors config
		if ( false === file_put_contents( $_config, $allowed_hosts ) )
		{
			throw new \Exception( "Failed to update CORS configuration." );
		}
	}

	/**
	 * @param $allowed_hosts
	 *
	 * @throws BadRequestException
	 */
	protected static function validateHosts( $allowed_hosts )
	{
		foreach ( $allowed_hosts as $_hostInfo )
		{
			$_host = Option::get( $_hostInfo, 'host', '' );
			if ( empty( $_host ) )
			{
				throw new BadRequestException( "Allowed hosts contains an empty host name." );
			}
		}
	}

	// REST interface implementation

	/**
	 * @SWG\Api(
	 *       path="/system", description="Operations available for system management.",
	 * @SWG\Operations(
	 * @SWG\Operation(
	 *       httpMethod="GET", summary="List resources available for system management.",
	 *       notes="See listed operations for each resource available.",
	 *       responseClass="Resources", nickname="getResources"
	 *     )
	 *   )
	 * )
	 *
	 * @return array
	 */
	protected function _listResources()
	{
		$resources = array(
			array( 'name' => 'app', 'label' => 'Application' ),
			array( 'name' => 'app_group', 'label' => 'Application Group' ),
			array( 'name' => 'config', 'label' => 'Configuration' ),
			array( 'name' => 'role', 'label' => 'Role' ),
			array( 'name' => 'service', 'label' => 'Service' ),
			array( 'name' => 'user', 'label' => 'User' ),
			array( 'name' => 'email_template', 'label' => 'Email Template' )
		);

		return array( 'resource' => $resources );
	}

	/**
	 *
	 * @return array|bool
	 * @throws \Exception
	 */
	protected function _handleResource()
	{
		switch ( $this->_resource )
		{
			case '':
				switch ( $this->_action )
				{
					case self::Get:
						return $this->_listResources();
						break;
					default:
						return false;
				}
				break;
			case 'config':
				$obj = new SystemConfig();

				return $obj->processRequest( $this->_action );
				break;
			case 'app':
			case 'app_group':
			case 'role':
			case 'service':
			case 'user':
			case 'email_template':
				$_resource = static::getNewResource( $this->_resource );
				$_resource->setResourceArray( $this->_resourceArray );

				return $_resource->processRequest( $this->_action );
				break;
			default:
				break;
		}

		return false;
	}

	/**
	 * @param $resource
	 *
	 * @throws \Platform\Exceptions\BadRequestException
	 * @return \App|\AppGroup|\Role|\Service|\User|\EmailTemplate
	 */
	public static function getResourceModel( $resource )
	{
		switch ( strtolower( $resource ) )
		{
			case 'app':
				$model = \App::model();
				break;
			case 'app_group':
			case 'appgroup':
				$model = \AppGroup::model();
				break;
			case 'role':
				$model = \Role::model();
				break;
			case 'service':
				$model = \Service::model();
				break;
			case 'user':
				$model = \User::model();
				break;
			case 'email_template':
				$model = \EmailTemplate::model();
				break;
			default:
				throw new BadRequestException( "Invalid system resource '$resource' requested." );
				break;
		}

		return $model;
	}

	/**
	 * @param $resource
	 *
	 * @return SystemApp|SystemAppGroup|SystemRole|SystemService|SystemUser|SystemEmailTemplate
	 * @throws InternalServerErrorException
	 */
	public static function getNewResource( $resource )
	{
		switch ( strtolower( $resource ) )
		{
			case 'app':
				$obj = new SystemApp;
				break;
			case 'app_group':
			case 'appgroup':
				$obj = new SystemAppGroup;
				break;
			case 'role':
				$obj = new SystemRole;
				break;
			case 'service':
				$obj = new SystemService;
				break;
			case 'user':
				$obj = new SystemUser;
				break;
			case 'email_template':
				$obj = new SystemEmailTemplate();
				break;
			default:
				throw new InternalServerErrorException( "Attempting to create an invalid system resource '$resource'." );
				break;
		}

		return $obj;
	}

	/**
	 * @param $resource
	 *
	 * @return \App|\AppGroup|\Role|\Service|\User|\EmailTemplate
	 * @throws InternalServerErrorException
	 */
	public static function getNewModel( $resource )
	{
		switch ( strtolower( $resource ) )
		{
			case 'app':
				$obj = new \App;
				break;
			case 'app_group':
			case 'appgroup':
				$obj = new \AppGroup;
				break;
			case 'role':
				$obj = new \Role;
				break;
			case 'service':
				$obj = new \Service;
				break;
			case 'user':
				$obj = new \User;
				break;
			case 'email_template':
				$obj = new \EmailTemplate();
				break;
			default:
				throw new InternalServerErrorException( "Attempting to create an invalid system model '$resource'." );
				break;
		}

		return $obj;
	}

	//-------- System Helper Operations -------------------------------------------------

	/**
	 * @param $id
	 *
	 * @return string
	 * @throws \Exception
	 */
	public static function getAppNameFromId( $id )
	{
		if ( !empty( $id ) )
		{
			try
			{
				$app = \App::model()->findByPk( $id );
				if ( isset( $app ) )
				{
					return $app->getAttribute( 'name' );
				}
			}
			catch ( \Exception $ex )
			{
				throw $ex;
			}
		}

		return '';
	}

	/**
	 * @param $name
	 *
	 * @return string
	 * @throws \Exception
	 */
	public static function getAppIdFromName( $name )
	{
		if ( !empty( $name ) )
		{
			try
			{
				$app = \App::model()->find( 'name=:name', array( ':name' => $name ) );
				if ( isset( $app ) )
				{
					return $app->getPrimaryKey();
				}
			}
			catch ( \Exception $ex )
			{
				throw $ex;
			}
		}

		return '';
	}

	/**
	 * @return string
	 */
	public static function getCurrentAppName()
	{
		return ( isset( $GLOBALS['app_name'] ) ) ? $GLOBALS['app_name'] : '';
	}

	/**
	 * @return string
	 */
	public static function getCurrentAppId()
	{
		return static::getAppIdFromName( static::getCurrentAppName() );
	}
}
