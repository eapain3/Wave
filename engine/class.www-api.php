<?php

/*
Wave Framework
API class

API class is one of the core classes of Wave Framework. Every command and function in Wave 
Framework is executed through API object. API class implements State class - which stores 
configuration - and executes any and all functionality that is built within Wave Framework. 
It is not recommended to modify this class, in fact this class is defined as final. Methods 
of this class take user input, load MVC objects and execute their methods and return data 
in the appropriate format.

Author and support: Kristo Vaher - kristo@waher.net
License: GNU Lesser General Public License Version 3
*/

final class WWW_API {
	
	// This variable is used to store results of API call. It acts as a buffer for API, which 
	// will be checked when another API call is made with the exact same input data.
	private $commandBuffer=array();
	
	// This holds data about API profiles from /resources/api.profiles.ini, content of which will 
	// be checked by API whenever API call is made that is not public.
	private $apiProfiles=array();
	
	// This variable holds data about API observers from /resources/api.observers.ini file. If 
	// an API call is made, then this variable content will be checked to execute additional 
	// API calls, if defined.
	private $apiObservers=array();
	
	// This is an array that stores data that will be logged by Logger object, if Logger is used 
	// in the system. Content length and other data is stored for logging purposes in this array.
	public $apiLoggerData=array();
	
	// This variable stores performance related timestamps, if splitTime() method is called 
	// by the API.
	private $splitTimes=array();

	// This variable stores the initialized State object that carries a lot of configuration 
	// and environment data and functionality.
	public $state=false;
	
	// This variable defines whether APC is available in the server environment. If this variable 
	// is true, then some caching methods will utilize APC instead of filesystem.
	public $apc=false;
	
	// This is a counter that stores the depth of API calls. Since API calls can execute other 
	// API calls, this variable is used to determine some caching and buffer related data when 
	// specific API call is references by API class.
	public $callIndex=0;
	
	// This is an index of cache files that have been referenced within the system. This is used 
	// so that certain calls do not have to be repeated, if the same cache is referred multiple 
	// times within a single request.
	public $cacheIndex=array();
	
	// This variable holds data about API execution call-index values and whether this specific 
	// API call can be cached or not. This is for internal maintenance when dealing with which 
	// API call to cache and which not.
	public $noCache=array();
	
	// This holds configuration value from State and turns on internal logging, if configuration 
	// has internal logging enabled. If this remains false, then internal log entries will not 
	// be stored.
	private $internalLogging=false;
	
	// This is an array that stores all the internal log entries that will be written to filesystem 
	// once API class has finished dealing with the request.
	private $internalLog=array();
			
	// API object construction accepts State object in $state and array data of API profiles 
	// as $apiProfiles. If State is not defined, then API class attempts to automatically 
	// create a new State object, thus API class is highly dependent on State class being 
	// present. API object construction also loads Factory class, if it is not defined and 
	// tests if server supports APC or not. If API profiles are not submitted to API during 
	// construction, then API will attempt to load API profiles from the *.ini file. Same 
	// applies to observers.
	// * state - Object of WWW_State class
	// * apiProfiles - Array of API profile information
	final public function __construct($state=false,$apiProfiles=false){

		// API expects to be able to use State object
		if($state){
			$this->state=$state;
		} else {
			// If State object does not exist, it is defined and loaded as State
			if(!class_exists('WWW_State')){
				require(__DIR__.DIRECTORY_SEPARATOR.'class.www-state.php');
			}
			$this->state=new WWW_State();
		}
		
		// If internal logging is used
		if($this->state->data['internal-logging'] && $this->state->data['internal-logging']!=''){
			$this->internalLogging=$this->state->data['internal-logging'];
		}
		
		// Factory class is loaded, if it doesn't already exist, since MVC classes require it
		if(!class_exists('WWW_Factory')){
			require(__DIR__.DIRECTORY_SEPARATOR.'class.www-factory.php');
		}
		
		// If APC is enabled
		if(extension_loaded('apc') && ini_get('apc.enabled')==1 && $this->state->data['apc']==1){
			$this->apc=true;
		}
		
		// System attempts to load API keys from the default location if they were not defined
		if(!$apiProfiles){
			// Profiles can be loaded from overrides folder as well
			if(file_exists(__ROOT__.'overrides'.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'api.profiles.ini')){
				$sourceUrl=__ROOT__.'overrides'.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'api.profiles.ini';
			} elseif(file_exists(__ROOT__.'resources'.DIRECTORY_SEPARATOR.'api.profiles.ini')){
				$sourceUrl=__ROOT__.'resources'.DIRECTORY_SEPARATOR.'api.profiles.ini';
			} else {
				return false;
			}
			// This data can also be stored in cache
			$cacheUrl=__ROOT__.'filesystem'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'api.profiles.tmp';
			// Testing if cache for profiles already exists
			$cacheTime=$this->cacheTime($cacheUrl);
			// If source file has been modified since cache creation
			if(!$cacheTime || filemtime($sourceUrl)>$cacheTime){
				// Profiles are parsed from INI file in the resources folder
				$apiProfiles=parse_ini_file($sourceUrl,true);
				$this->setCache($cacheUrl,$apiProfiles);
			} else {
				// Returning data from cache
				$apiProfiles=$this->getCache($cacheUrl);
			}
		}
		
		// Assigning API profiles
		$this->apiProfiles=$apiProfiles;
		
		// Observers can be loaded from overrides folder as well
		if(file_exists(__ROOT__.'overrides'.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'api.observers.ini')){
			$sourceUrl=__ROOT__.'overrides'.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'api.observers.ini';
		} elseif(file_exists(__ROOT__.'resources'.DIRECTORY_SEPARATOR.'api.observers.ini')){
			$sourceUrl=__ROOT__.'resources'.DIRECTORY_SEPARATOR.'api.observers.ini';
		} else {
			return false;
		}
		// This data can also be stored in cache
		$cacheUrl=__ROOT__.'filesystem'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'api.observers.tmp';

		// Testing if cache for observers already exists
		$cacheTime=$this->cacheTime($cacheUrl);
		// If source file has been modified since cache creation
		if(!$cacheTime || filemtime($sourceUrl)>$cacheTime){
			// Profiles are parsed from INI file in the resources folder
			$this->apiObservers=parse_ini_file($sourceUrl,true);
			$this->setCache($cacheUrl,$this->apiObservers);
		} else {
			// Returning data from cache
			$this->apiObservers=$this->getCache($cacheUrl);
		}
		
	}
	
	// Once API object is not used anymore, the object attempts to write internal log to 
	// filesystem if internal log is used and has any log data to store.
	final public function __destruct(){
		if($this->internalLogging && !empty($this->internalLog)){
			file_put_contents(__ROOT__.'filesystem'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'internal.log',json_encode($this->internalLog)."\n",FILE_APPEND);
		}
	}
	
	// API COMMAND
	
		// This is one of the two core methods of API class. It accepts input data from 
		// $apiInputData which is an array of keys and values. Some keys are API dependent 
		// flags with the wave prefix of 'www-'. $useBuffer setting defines if buffer can be 
		// used, which means that if the same exact input has already been sent within the 
		// same HTTP request, then it returns data from buffer rather than going through the 
		// process again. $apiValidation is a flag that sets whether API profiles are 
		// validated or not, this setting is turned off for internal API calls that have 
		// already been validated. $useLogger is a flag that tells API that Logger class 
		// is used by the system. This method validates the API call, loads MVC objects and 
		// executes their methods and sends the result to output() function.
		// * apiInputData - array of input data
		// * useBuffer - Whether API calls are buffered per request
		// * apiValidation - internally called API does not need to be hash validated, unless necessary
		// * useLogger - Whether logger array is updated during execution
		final public function command($apiInputData=array(),$useBuffer=false,$apiValidation=true,$useLogger=false){
		
			// Increasing the counter of API calls
			$this->callIndex++;
		
			// If internal logging is enabled
			if($this->internalLogging){
				$this->internalLogEntry('input-data',$apiInputData);
			}
		
			// DEFAULT VALUES, COMMAND AND BUFFER CHECK
			
				// This stores information about current API command state
				// Various defaults are loaded from State
				$apiState=array(
					'call-index'=>$this->callIndex,
					'cache-timeout'=>false,
					'command'=>false,
					'content-type-header'=>(isset($apiInputData['www-content-type']))?$apiInputData['www-content-type']:false,
					'custom-header'=>false,
					'hash'=>false,
					'hash-validation'=>true,
					'ip-session'=>false,
					'last-modified'=>$this->state->data['request-time'],
					'minify-output'=>(isset($apiInputData['www-minify']))?$apiInputData['www-minify']:false,
					'disable-callbacks'=>(isset($apiInputData['www-disable-callbacks']))?$apiInputData['www-disable-callbacks']:false,
					'output-crypt-key'=>false,
					'profile'=>$this->state->data['api-public-profile'],
					'push-output'=>(isset($apiInputData['www-output']))?$apiInputData['www-output']:true,
					'return-hash'=>(isset($apiInputData['www-return-hash']))?$apiInputData['www-return-hash']:false,
					'return-timestamp'=>(isset($apiInputData['www-return-timestamp']))?$apiInputData['www-return-timestamp']:false,
					'return-type'=>(isset($apiInputData['www-return-type']))?$apiInputData['www-return-type']:'json',
					'secret-key'=>false,
					'token'=>false,
					'state-key'=>false,
					'commands'=>'*',
					'token-file'=>false,
					'token-directory'=>false,
					'token-timeout'=>false
				);
				
				// Turning output off if the HTTP HEAD request is made
				if($this->state->data['http-request-method']=='HEAD'){
					$apiState['push-output']=false;
				}
		
				// If API command is not set and is not set in input array either, the system will return an error
				if(isset($apiInputData['www-command'])){
					$apiState['command']=strtolower($apiInputData['www-command']);
				} elseif(isset($apiInputData['www-controller'])){
					$apiState['command']=$apiInputData['www-controller'].'-'.strtolower($this->state->data['http-request-method']);
				} else {
					return $this->output(array('www-message'=>'API command not set','www-response-code'=>101),$apiState);
				}
				
				// Input observer
				if(isset($this->apiObservers[$apiState['command']]) && isset($this->apiObservers[$apiState['command']]['input']) && $this->apiObservers[$apiState['command']]['input']!=''){
					$this->command(array('www-return-type'=>'php','www-command'=>$this->apiObservers[$apiState['command']]['input'],'www-output'=>0,'www-return-hash'=>0,'www-content-type'=>false,'www-minify'=>false,'www-crypt-output'=>false,'www-cache-tags'=>false)+$apiInputData,((isset($this->apiObservers[$apiState['command']]['input-buffer']) && $this->apiObservers[$apiState['command']]['input-buffer']==true)?true:false),false);
				}
				
				// Sorting the input array
				$apiInputData=$this->ksortArray($apiInputData);
				
				// Existing response is checked from buffer if it exists
				if($useBuffer){
					$commandBufferAddress=md5($apiState['command'].serialize($apiInputData));
					// If result already exists in buffer then it is simply returned
					if(isset($this->commandBuffer[$commandBufferAddress])){
						return $this->commandBuffer[$commandBufferAddress];
					}
				}
				
				// This notifies state what language is used
				if(isset($apiInputData['www-language'])){
					if(in_array($apiInputData['www-language'],$this->state->data['languages'])){
						$this->state->data['language']=$apiInputData['www-language'];
					} else {
						return $this->output(array('www-message'=>'This language is not defined','www-response-code'=>115),$apiState);
					}
				}
				
				// This can be passed to prevent cross-site forgeries
				if(isset($apiInputData['www-state'])){
					$apiState['state-key']=$apiInputData['www-state'];
				}
				
				// This tests if cache value sent through input is valid
				if($apiState['command']!='www-create-session' && isset($apiInputData['www-cache-timeout']) && $apiInputData['www-cache-timeout']>=0 && !isset($apiInputData['www-files'])){
					$apiState['cache-timeout']=$apiInputData['www-cache-timeout'];
				}
				
			// VALIDATING PROFILE BASED INPUT DATA
				
				// API profile data is loaded only if API validation is used
				if($apiValidation){
				
					// Current API profile is assigned to state
					// This is useful for controller development if you wish to restrict certain controllers to only certain API profiles
					if(isset($apiInputData['www-profile']) && $apiInputData['www-profile']!=$this->state->data['api-public-profile']){
						$apiState['profile']=$apiInputData['www-profile'];
					} else {
						$apiState['profile']=$this->state->data['api-public-profile'];
					}
					
					// This checks whether API profile information is defined in /resources/api.profiles.php file
					if(isset($this->apiProfiles[$apiState['profile']])){
					
						// Testing if API profile is disabled or not
						if(isset($this->apiProfiles[$apiState['profile']]['disabled']) && $this->apiProfiles[$apiState['profile']]['disabled']==1){
							// If profile is set to be disabled
							return $this->output(array('www-message'=>'API profile is disabled','www-response-code'=>103),$apiState);
						} 
						
						// Testing if IP is in valid range
						if(isset($this->apiProfiles[$apiState['profile']]['ip']) && $this->apiProfiles[$apiState['profile']]['ip']!='*' && !in_array($this->state->data['client-ip'],explode(',',$this->apiProfiles[$apiState['profile']]['ip']))){
							// If profile has IP set and current IP is not allowed
							return $this->output(array('www-message'=>'API profile not allowed from this IP','www-response-code'=>104),$apiState);
						}
						
						// Profile commands are filtered only if they are set
						if(isset($this->apiProfiles[$apiState['profile']]['commands']) && $apiState['command']!='www-create-session' && $apiState['command']!='www-destroy-session' && $apiState['command']!='www-validate-session'){
							$apiState['commands']=explode(',',$this->apiProfiles[$apiState['profile']]['commands']);
							if((in_array('*',$apiState['commands']) && in_array('!'.$apiState['command'],$apiState['commands'])) || (!in_array('*',$apiState['commands']) && !in_array($apiState['command'],$apiState['commands']))){
								// If profile has IP set and current IP is not allowed
								return $this->output(array('www-message'=>'API command is not allowed for this profile','www-response-code'=>105),$apiState);
							}
						}
						
						// These options only affect non-public profiles
						if($apiState['profile']!=$this->state->data['api-public-profile']){
						
							// Returns an error if timestamp validation is required but www-timestamp is not provided						
							if(isset($this->apiProfiles[$apiState['profile']]['timestamp-timeout'])){
								// Timestamp value has to be set and not be empty
								if(!isset($apiInputData['www-timestamp']) || $apiInputData['www-timestamp']==''){
									return $this->output(array('www-message'=>'API request validation timestamp is missing','www-response-code'=>106),$apiState);
								} elseif($this->apiProfiles[$apiState['profile']]['timestamp-timeout']<($this->state->data['request-time']-$apiInputData['www-timestamp'])){
									return $this->output(array('www-message'=>'API request timestamp is too old','www-response-code'=>107),$apiState);
								}
							}
							
							// If hash validation is turned off
							if(isset($this->apiProfiles[$apiState['profile']]['hash-validation']) && $this->apiProfiles[$apiState['profile']]['hash-validation']==0){
								$apiState['hash-validation']=false;
							}
							
							// Returns an error if secret key is not set for an API profile
							if(isset($this->apiProfiles[$apiState['profile']]['secret-key'])){
							
								// only checked if hash-based validation is used
								if($apiState['hash-validation']){
									// Hash value has to be set and not be empty
									if(!isset($apiInputData['www-hash']) || $apiInputData['www-hash']==''){
										return $this->output(array('www-message'=>'API request validation hash is missing','www-response-code'=>109),$apiState);
									} else {
										// Validation hash
										$apiState['hash']=$apiInputData['www-hash'];
									}
								}
								
								// Secret key
								$apiState['secret-key']=$this->apiProfiles[$apiState['profile']]['secret-key'];
								
							} else {
								return $this->output(array('www-message'=>'API profile configuration incorrect: Secret key missing from configuration','www-response-code'=>108),$apiState);
							}
							
							// Checks for whether token timeout is set on the API profile					
							if(isset($this->apiProfiles[$apiState['profile']]['token-timeout']) && $this->apiProfiles[$apiState['profile']]['token-timeout']!=0){
								// Since this is not null, token based validation is used
								$apiState['token-timeout']=$this->apiProfiles[$apiState['profile']]['token-timeout'];
							}
						
						}
						
					} else {
						return $this->output(array('www-message'=>'API profile not found','www-response-code'=>102),$apiState);
					}

				}
				
			// API PROFILE HASH AND TOKEN VALIDATION
			
				// API profile validation happens only if non-public profile is actually set
				if($apiValidation && $apiState['profile'] && $apiState['profile']!=$this->state->data['api-public-profile']){
				
					// TOKEN CHECKS
						
						// Session filename is a simple hashed API profile name
						$apiState['token-file']=md5($apiState['profile'].$this->apiProfiles[$apiState['profile']]['secret-key']).'.tmp';
						$apiState['token-file-ip']=md5($this->state->data['client-ip'].$apiState['profile'].$this->apiProfiles[$apiState['profile']]['secret-key']).'.tmp';
						// Session folder in filesystem
						$apiState['token-directory']=$this->state->data['system-root'].'filesystem'.DIRECTORY_SEPARATOR.'tokens'.DIRECTORY_SEPARATOR.substr($apiState['token-file'],0,2).DIRECTORY_SEPARATOR;
						$apiState['token-directory-ip']=$this->state->data['system-root'].'filesystem'.DIRECTORY_SEPARATOR.'tokens'.DIRECTORY_SEPARATOR.substr($apiState['token-file-ip'],0,2).DIRECTORY_SEPARATOR;
					
						// Checking if valid token is active
						// It is possible that the token was created with linked IP, which is checked here
						if(file_exists($apiState['token-directory-ip'].$apiState['token-file-ip']) && (!$apiState['token-timeout'] || filemtime($apiState['token-directory-ip'].$apiState['token-file-ip'])>=($this->state->data['request-time']-$apiState['token-timeout']))){
						
							// Loading contents of current token file to API state
							$apiState['token']=file_get_contents($apiState['token-directory-ip'].$apiState['token-file-ip']);
							// This updates the last-modified time, thus postponing the time how long the token is considered valid
							touch($apiState['token-directory-ip'].$apiState['token-file-ip']);
							// Setting the IP directories to regular addresses
							$apiState['token-file']=$apiState['token-file-ip'];
							$apiState['token-directory']=$apiState['token-directory-ip'];
						
						} elseif(file_exists($apiState['token-directory'].$apiState['token-file']) && (!$apiState['token-timeout'] || filemtime($apiState['token-directory'].$apiState['token-file'])>=($this->state->data['request-time']-$apiState['token-timeout']))){
							
							// Loading contents of current token file to API state
							$apiState['token']=file_get_contents($apiState['token-directory'].$apiState['token-file']);
							// This updates the last-modified time, thus postponing the time how long the token is considered valid
							touch($apiState['token-directory'].$apiState['token-file']);
							
						} elseif($apiState['command']=='www-create-session' && isset($apiInputData['www-ip-session']) && $apiInputData['www-ip-session']==true){
						
							$apiState['ip-session']=true;
							// Setting the IP directories to regular addresses
							$apiState['token-file']=$apiState['token-file-ip'];
							$apiState['token-directory']=$apiState['token-directory-ip'];
							
						} elseif($apiState['command']!='www-create-session'){
						
							// Token is not required for commands that create or destroy existing tokens
							return $this->output(array('www-message'=>'API session token does not exist or is timed out','www-response-code'=>110),$apiState);
							
						}
						
					// TOKEN AND HASH VALIDATION
					
						// If hash validation is used
						if($apiState['hash-validation']){
						
							// Validation hash is calculated from input data
							$validationData=$apiInputData;
							// Session input is not considered for validation hash and is unset
							unset($validationData['www-hash'],$validationData['www-session'],$validationData['www-cookie'],$validationData['www-files']);
							
							// If token is set then this is used for validation as long as the command is not www-create-session
							if($apiState['token'] && $apiState['command']!='www-create-session'){
								// Non-session creating hash validation is a little different and takes into account both token and the secret key
								$validationHash=sha1(http_build_query($validationData).$apiState['token'].$apiState['secret-key']);
							} else {
								// Session creation commands have validation hashes built only with the secret key
								$validationHash=sha1(http_build_query($validationData).$apiState['secret-key']);
							}
							
							// Unsetting validation data array
							unset($validationData);
							
							// If validation hashes do not match
							if($validationHash!=$apiState['hash']){
								return $this->output(array('www-message'=>'API profile authentication failed: input hash validation failed','www-response-code'=>111),$apiState);
							}
						
						} elseif($apiState['command']!='www-create-session' && (!isset($apiInputData['www-token']) || $apiState['token']!=$apiInputData['www-token'])){
						
							// If hash validation is used, then request can be made with a token or secret key alone and if these do not match then authentication error is thrown
							return $this->output(array('www-message'=>'API profile authentication failed: session token is missing or incorrect','www-response-code'=>111),$apiState);
						
						} elseif($apiState['command']=='www-create-session' && (!isset($apiInputData['www-secret-key']) || $apiInputData['www-secret-key']!=$apiState['secret-key'])){
						
							// If hash validation is used, then request can be made with a token or secret key alone and if these do not match then authentication error is thrown
							return $this->output(array('www-message'=>'API profile authentication failed: secret key is missing or incorrect','www-response-code'=>111),$apiState);
							
						}
						
					// HANDLING CRYPTED INPUT
						
						// If crypted input is set
						if(isset($apiInputData['www-crypt-input'])){
							// Mcrypt is required for decryption
							if(extension_loaded('mcrypt')){
								// Rijndael 256 bit decryption is used in CBC mode
								if($apiState['token'] && $apiState['command']!='www-create-session'){
									$decryptedData=$this->decryptRijndael256($apiInputData['www-crypt-input'],$apiState['token'],$apiState['secret-key']);
								} else {
									$decryptedData=$this->decryptRijndael256($apiInputData['www-crypt-input'],$apiState['secret-key']);
								}
								if($decryptedData){
									// Unserializing crypted data with JSON
									$decryptedData=json_decode($decryptedData,true);
									// Unserialization can fail if the data is not in correct format
									if($decryptedData && is_array($decryptedData)){
										// Merging crypted input with set input data
										$apiInputData=$decryptedData+$apiInputData;
									} else {
										return $this->output(array('www-message'=>'Problem decrypting encrypted data: Decrypted data is not a JSON encoded array','www-response-code'=>113),$apiState);
									}
								} else {
									return $this->output(array('www-message'=>'Problem decrypting encrypted data: Decryption failed','www-response-code'=>113),$apiState);
								}	
							} else {
								return $this->output(array('www-message'=>'Problem decrypting encrypted data: No tools to decrypt data','www-response-code'=>113),$apiState);
							}
						}
						
						// If this is set, then the value of this is used to crypt the output
						// Please note that while www-crypt-output key can be set outside www-crypt-input data, it is recommended to keep that key within crypted input when making a request
						if(isset($apiInputData['www-crypt-output'])){
							$apiState['output-crypt-key']=$apiInputData['www-crypt-output'];
						}
						
					// SESSION CREATION, DESTRUCTION AND VALIDATION COMMANDS
					
						// These two commands are an exception to the rule, these are the only non-public profile commands that can be executed without requiring a valid token
						if($apiState['command']=='www-create-session'){
						
							// If session token subdirectory does not exist, it is created
							if(!is_dir($apiState['token-directory'])){
								if(!mkdir($apiState['token-directory'],0777)){
									return $this->output(array('www-message'=>'Server configuration error: Cannot create session token folder','www-response-code'=>100),$apiState);
								}
							}
							// Token for API access is generated simply from current profile name and request time
							$apiState['token']=md5($apiState['profile'].$this->state->data['request-time']);
							// Session token file is created and token itself is returned to the user agent as a successful request
							if(file_put_contents($apiState['token-directory'].$apiState['token-file'],$apiState['token'])){
								// Token is returned to user agent together with current token timeout setting
								if($apiState['token-timeout']){
									// Returning current IP together with the session
									if($apiState['ip-session']){
										return $this->output(array('www-message'=>'API session token created','www-token'=>$apiState['token'],'www-token-timeout'=>$apiState['token-timeout'],'www-ip-session'=>$this->state->data['client-ip'],'www-response-code'=>500),$apiState);
									} else {
										return $this->output(array('www-message'=>'API session token created','www-token'=>$apiState['token'],'www-token-timeout'=>$apiState['token-timeout'],'www-response-code'=>500),$apiState);
									}
								} else {
									// Since token timeout is not set, the token is assumed to be infinite
									return $this->output(array('www-message'=>'API session token created','www-token'=>$apiState['token'],'www-token-timeout'=>'infinite','www-response-code'=>500),$apiState);
								}
							} else {
								return $this->output(array('www-message'=>'Server configuration error: Cannot create session token file','www-response-code'=>100),$apiState);
							}

						} elseif($apiState['command']=='www-destroy-session'){
						
							// Making sure that the token file exists, then removing it
							if(file_exists($apiState['token-directory'].$apiState['token-file'])){
								unlink($apiState['token-directory'].$apiState['token-file']);
							}
							// Returning success message
							return $this->output(array('www-message'=>'API session destroyed','www-response-code'=>500),$apiState);
						
						} elseif($apiState['command']=='www-validate-session'){
							// This simply returns output
							return $this->output(array('www-message'=>'API session validation successful','www-response-code'=>500),$apiState);
						}	
				
				} else if(in_array($apiState['command'],array('www-create-session','www-destroy-session','www-validate-session'))){
					// Since public profile is used, the session-related tokens cannot be used
					return $this->output(array('www-message'=>'API session token commands cannot be used with public profile','www-response-code'=>112),$apiState);
				}
			
			// CACHE HANDLING IF CACHE IS USED
				
				// Result of the API call is stored in this variable
				$apiResult=false;
				
				// This stores a flag about whether cache is used or not
				$this->apiLoggerData['cache-used']=false;
				$this->apiLoggerData['www-command']=$apiState['command'];
			
				// If cache timeout is set
				// If this value is 0, then no cache is used for command
				if($apiState['cache-timeout']){
				
					// Calculating cache validation string
					$cacheValidator=$apiInputData;
					// If session namespace is defined, it is removed from cookies for cache validation
					unset($cacheValidator['www-cookie'][$this->state->data['session-namespace']],$cacheValidator['www-cache-tags'],$cacheValidator['www-hash'],$cacheValidator['www-state'],$cacheValidator['www-timestamp'],$cacheValidator['www-crypt-output'],$cacheValidator['www-cache-timeout'],$cacheValidator['www-return-type'],$cacheValidator['www-output'],$cacheValidator['www-return-hash'],$cacheValidator['www-return-timestamp'],$cacheValidator['www-content-type'],$cacheValidator['www-minify'],$cacheValidator['www-crypt-input'],$cacheValidator['www-xml'],$cacheValidator['www-json'],$cacheValidator['www-ip-session'],$cacheValidator['www-disable-callbacks']);

					// MD5 is used for slight performance benefits over sha1() when calculating cache validation hash string
					$cacheValidator=md5($apiState['command'].serialize($cacheValidator).$apiState['return-type'].$apiState['push-output']);
					
					// Cache filename consists of API command, serialized input data, return type and whether API output is used.
					$cacheFile=$cacheValidator.'.tmp';
					// Setting cache folder
					$cacheFolder=$this->state->data['system-root'].'filesystem'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'output'.DIRECTORY_SEPARATOR.substr($cacheFile,0,2).DIRECTORY_SEPARATOR;
					
					// If cache file exists, it will be parsed and set as API value
					if(file_exists($cacheFolder.$cacheFile)){
					
						// Setting the path of cache file, since it exists
						$this->cacheIndex[$this->callIndex]=$cacheFolder.$cacheFile;
					
						// Current cache timeout is used to return to browser information about how long browser should store this result
						$apiState['last-modified']=$this->cacheTime($cacheFolder.$cacheFile);
						
						// If server detects its cache to still within cache limit
						if($apiState['last-modified']>=($this->state->data['request-time']-$apiState['cache-timeout'])){
							// If this request has already been made and the last-modified timestamp is exactly the same
							if($apiState['push-output'] && $this->state->data['http-if-modified-since'] && $this->state->data['http-if-modified-since']>=$apiState['last-modified']){
								// Adding log data
								if($useLogger){
									$this->apiLoggerData['cache-used']=true;
									$this->apiLoggerData['response-code']=304;
								}
								// Cache headers (Last modified is never sent with 304 header)
								if($this->state->data['http-authentication']==true){
									header('Cache-Control: private,max-age='.($apiState['last-modified']+$apiState['cache-timeout']-$this->state->data['request-time']).'');
								} else {
									header('Cache-Control: public,max-age='.($apiState['last-modified']+$apiState['cache-timeout']-$this->state->data['request-time']).'');
								}
								header('Expires: '.gmdate('D, d M Y H:i:s',($apiState['last-modified']+$apiState['cache-timeout'])).' GMT');
								// Returning 304 header
								header('HTTP/1.1 304 Not Modified');
								return true;
							}
							
							// System loads the result from cache file based on return data type
							$apiResult=$this->getCache($cacheFolder.$cacheFile);
							
							// Since cache was used
							$this->apiLoggerData['cache-used']=true;
							
						} else {
							// Since cache seems to be outdated, a new one will be generated with new request time
							$apiState['last-modified']=$this->state->data['request-time'];
						}
						
					} else {
						// Current cache timeout is used to return to browser information about how long browser should store this result
						$apiState['last-modified']=$this->state->data['request-time'];
					}
					
				} else {
					// Since cache is not used, last modified time is the time of the request
					$apiState['last-modified']=$this->state->data['request-time'];
				}
			
			// SOLVING API RESULT IF RESULT WAS NOT FOUND IN CACHE
			
				// If cache was not used and command result is not yet defined, system will execute the API command
				if(!$apiResult){
				
					// API command is solved into bits to be parsed
					$commandBits=explode('-',$apiState['command'],2);
					// Class name is found based on command
					$className='WWW_controller_'.$commandBits[0];
					// Class is defined and loaded, if it is not already defined
					if(!class_exists($className)){
						// Overrides can be used for controllers
						if(file_exists($this->state->data['system-root'].'overrides'.DIRECTORY_SEPARATOR.'controllers'.DIRECTORY_SEPARATOR.'controller.'.$commandBits[0].'.php')){
							require($this->state->data['system-root'].'overrides'.DIRECTORY_SEPARATOR.'controllers'.DIRECTORY_SEPARATOR.'controller.'.$commandBits[0].'.php');
						} elseif(file_exists($this->state->data['system-root'].'controllers'.DIRECTORY_SEPARATOR.'controller.'.$commandBits[0].'.php')){
							require($this->state->data['system-root'].'controllers'.DIRECTORY_SEPARATOR.'controller.'.$commandBits[0].'.php');
						} else {
							// Since an error was detected, system pushes for output immediately
							return $this->output(array('www-message'=>'API request recognized, but unable to handle','www-response-code'=>114),$apiState);
						}
					}
					
					// Second half of the command string is used to solve the function that is called by the command
					if(isset($commandBits[1])){
					
						// Solving method name, dashes are underscored
						$methodName=str_replace('-','_',$commandBits[1]);
						// New controller is created based on API call
						$controller=new $className($this,$this->callIndex);
						// If command method does not exist, 501 page is returned or error triggered
						if(!method_exists($controller,$methodName) || !is_callable(array($controller,$methodName))){
							// Since an error was detected, system pushes for output immediately
							return $this->output(array('www-message'=>'API request recognized, but unable to handle','www-response-code'=>114),$apiState);
						}
						// Gathering every possible echoed result from method call
						ob_start();
						// Result of the command is solved with this call
						// Input data is also submitted to this function
						$apiResult=$controller->$methodName($apiInputData);
						// If the method does not return anything in the result, then building an API result array
						if($apiResult==null){
							$apiResult=array('www-message'=>'OK','www-response-code'=>500);
						} elseif(!is_array($apiResult)){
							$apiResult=array('www-message'=>'OK','www-response-code'=>500,'www-output'=>$apiResult);
						}
						// Catching everything that was echoed and adding to array, unless output is already populated
						if(ob_get_length()>0){
							// If string was returned from previous result, then output buffer is ignored
							if(!isset($apiResult['www-data'])){
								$apiResult['www-data']=ob_get_clean();
							} else {
								ob_end_clean();
							}
						} else {
							ob_end_clean();
						}
					} else {
						// Since an error was detected, system pushes for output immediately
						return $this->output(array('www-message'=>'API request recognized, but unable to handle','www-response-code'=>114),$apiState);
					}
					
					// If cache timeout was set then the result is stored as a cache in the filesystem
					if($apiState['cache-timeout']){
					
						// If cache has not been disallowd by any of the API calls
						if(!isset($this->noCache[$apiState['call-index']]) || $this->noCache[$apiState['call-index']]==false){
						
							// If cache subdirectory does not exist, it is created
							if(!is_dir($cacheFolder)){
								if(!mkdir($cacheFolder,0777)){
									return $this->output(array('www-message'=>'Server configuration error: Cannot create cache folder','www-response-code'=>100),$apiState);
								}
							}
							// Cache is stored in serialized form
							$this->setCache($cacheFolder.$cacheFile,$apiResult);
							
							// If cache tag is set then system stores link to cache file
							if(isset($apiInputData['www-cache-tags'],$cacheFolder,$cacheFile) && $apiInputData['www-cache-tags']!=''){
								$cacheTags=explode(',',$apiInputData['www-cache-tags']);
								foreach($cacheTags as $tag){
									if(!file_put_contents($this->state->data['system-root'].'filesystem'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'tags'.DIRECTORY_SEPARATOR.md5($tag).'.tmp',$cacheFolder.$cacheFile."\n",FILE_APPEND)){
										return $this->output(array('www-message'=>'Server configuration error: Cannot create cache tag index','www-response-code'=>100),$apiState);
									}
								}
							}
							
						} else {
							// Setting cache timeout to 0, since cache is not stored
							$apiState['cache-timeout']=0;
						}
						
					}
					
				}
							
			// SENDING RESULT TO OUTPUT
				
				// Output observer
				if(isset($this->apiObservers[$apiState['command']]) && isset($this->apiObservers[$apiState['command']]['output']) && $this->apiObservers[$apiState['command']]['output']!=''){
					// Output observer is called differently based on whether the returned result was an array or not
					if(!is_array($apiResult)){
						$this->command(array('result'=>$apiResult,'www-return-type'=>'php','www-command'=>$this->apiObservers[$apiState['command']]['output'],'www-output'=>0,'www-return-hash'=>0,'www-content-type'=>false,'www-minify'=>false,'www-crypt-output'=>false,'www-cache-tags'=>false),((isset($this->apiObservers[$apiState['command']]['output-buffer']) && $this->apiObservers[$apiState['command']]['output-buffer']==true)?true:false),false);
					} else {
						$this->command(array('www-cache-timeout'=>((isset($this->apiObservers[$apiState['command']]['output-buffer']))?$this->apiObservers[$apiState['command']]['output-buffer']:0),'www-return-type'=>'php','www-command'=>$this->apiObservers[$apiState['command']]['output'],'www-output'=>0,'www-return-hash'=>0,'www-content-type'=>false,'www-minify'=>false,'www-crypt-output'=>false,'www-cache-tags'=>false)+$apiResult,((isset($this->apiObservers[$apiState['command']]['output-buffer']) && $this->apiObservers[$apiState['command']]['output-buffer']==true)?true:false),false);
					}
				}
			
				// If buffer is not disabled, response is checked from buffer
				if($useBuffer){
					// Storing result in buffer
					$this->commandBuffer[$commandBufferAddress]=$this->output($apiResult,$apiState,$useLogger);
					// Returning result from newly created buffer
					return $this->commandBuffer[$commandBufferAddress];
				} else {
					// System returns correctly formatted output data
					return $this->output($apiResult,$apiState,$useLogger);
				}
			
		}
	
	// OUTPUT
	
		// This is one of the two core methods of API class. Method is private and is only 
		// called within the class. This method is used to parse the data returned from API 
		// and returned to the user agent or system based on requested format. $apiResult is 
		// an array that has been returned from command() method, $apiState and $useLogger are 
		// also defined when the method is called. It returns the data as a PHP array, XML 
		// string, INI string or any other format and with or without HTTP response headers.
		// * apiResult - Result of the API call
		// * apiState - Various flags from command execution
		// * useLogger - If logger is used
		final private function output($apiResult,$apiState,$useLogger=true){
				
			// If internal logging is enabled
			if($this->internalLogging){
				$this->internalLogEntry('output-data',$apiResult);
			}
			
			// This filters the result through various PHP and header specific commands
			if($apiState['disable-callbacks']==false && (!isset($apiResult['www-disable-callbacks']) || $apiResult['www-disable-callbacks']==false)){
				$this->apiCallbacks($apiResult,$useLogger);
			}
					
			// Simple flag for error check, this is used for output encryption
			$errorFound=false;
			
			// Errors are detected based on response code
			if(isset($apiResult['www-response-code']) && $apiResult['www-response-code']<400){
				if($apiState['return-type']=='php'){
					// Throwing a PHP warning, if the error is either system or API Wrapper specific
					if(isset($apiResult['www-message'])){
						trigger_error($apiResult['www-message'],E_USER_WARNING);
					} else {
						trigger_error('Undefined error with error code #'.$apiResult['www-response-code'],E_USER_WARNING);
					}
					return false;
				}
				$errorFound=true;
			}
			
			// DATA CONVERSION FROM RESULT TO REQUESTED FORMAT
			
				// If actual output is already detected
				if(isset($apiResult['www-data'])){
				
					// Actual output is stored in www-output key
					$apiResult=$apiResult['www-data'];
					
				} else {
				
					// If state was defined
					if($apiState['state-key']){
						$apiResult['www-state']=$apiState['state-key'];
					}
				
					// OUTPUT HASH VALIDATION
				
					// If timestamp is required to be returned
					if($apiState['return-timestamp']){
						$apiResult['www-timestamp']=time();
					}
				
					// If request demanded a hash to also be returned
					// This is only valid when the result is not an 'error' and has a secret key set
					if($apiState['return-hash'] && $apiState['secret-key']){
						
						// Hash is written to returned result
						// Session creation and destruction commands return data is hashed without token
						if(!$apiState['token-timeout'] || $apiState['command']=='www-create-session'){
							$apiResult['www-hash']=sha1(http_build_query($this->ksortArray($apiResult)),$apiState['secret-key']);
						} else {
							$apiResult['www-hash']=sha1(http_build_query($this->ksortArray($apiResult)),$apiState['token'].$apiState['secret-key']);
						}
						
					}
		
					// Data is custom-formatted based on request
					switch($apiState['return-type']){
						case 'json':
							// Encodes the resulting array in JSON
							$apiResult=json_encode($apiResult);
							break;
						case 'binary':
							// If the result is empty string or empty array or false, then binary returns a 0, otherwise it returns 1
							$apiResult=$this->toBinary($apiResult);
							break;
						case 'xml':
							// Result array is turned into an XML string
							$apiResult=$this->toXML($apiResult);
							break;
						case 'rss':
							// Result array is turned into an XML string
							// The data should be formatted based on RSS 2.0 specification
							$apiResult=$this->toXML($apiResult,'rss');
							break;
						case 'csv':
							// Result array is turned into a CSV file
							$apiResult=$this->toCSV($apiResult);
							break;
						case 'serializedarray':
							// Array is simply serialized
							$apiResult=serialize($apiResult);
							break;
						case 'querystring':
							// Array is built into serialized query string
							$apiResult=http_build_query($apiResult);
							break;
						case 'ini':
							// This converts result into an INI string
							$apiResult=$this->toINI($apiResult);
							break;
						case 'php':
							// If PHP is used, then it can not be 'echoed' out due to being a PHP variable, so this is turned off
							$apiState['push-output']=false;
							break;
					}
				
				}
			
			// MINIFICATION
		
				// If minification is requested from API
				if($apiState['minify-output']==1){
				
					// Including minification class if it is not yet defined
					if(!class_exists('WWW_Minify')){
						require(__DIR__.DIRECTORY_SEPARATOR.'class.www-minifier.php');
					}
					
					// Minification is based on the type of class
					switch($apiState['return-type']){
						case 'xml':
							// XML minification eliminates extra spaces and newlines and other formatting
							$apiResult=WWW_Minifier::minifyXML($apiResult);
							break;
						case 'html':
							// HTML minification eliminates extra spaces and newlines and other formatting
							$apiResult=WWW_Minifier::minifyHTML($apiResult);
							break;
						case 'js':
							// JavaScript minification eliminates extra spaces and newlines and other formatting
							$apiResult=WWW_Minifier::minifyJS($apiResult);
							break;
						case 'css':
							// CSS minification eliminates extra spaces and newlines and other formatting
							$apiResult=WWW_Minifier::minifyCSS($apiResult);
							break;
						case 'rss':
							// RSS minification eliminates extra spaces and newlines and other formatting
							$apiResult=WWW_Minifier::minifyXML($apiResult);
							break;
					}
					
				}
			
			// OUTPUT ENCRYPTION
			
				if($apiState['push-output'] && $apiState['output-crypt-key'] && !$errorFound){
					// Returned result will be with plain text instead of requested format, but only if header is not already overwritten
					if(!$apiState['content-type-header']){
						$apiState['content-type-header']='Content-Type: text/plain;charset=utf-8';
					}
					// If token timeout is set, then profile must be defined
					if($apiState['secret-key']){
						// If secret key is set, then output will be crypted with CBC mode
						$apiResult=$this->encryptRijndael256($apiResult,$apiState['output-crypt-key'],$apiState['secret-key']);
					} else {
						// If secret key is not set (for public profiles), then output will be crypted with ECB mode
						$apiResult=$this->encryptRijndael256($apiResult,$apiState['output-crypt-key']);
					}
				}
			
			// FINAL OUTPUT OF RESULT
		
				// Result is printed out, headers and cache control are returned to the user agent, if output flag was set for the command
				if($apiState['push-output']){
				
					// CACHE AND CUSTOM HEADERS
				
						// Cache control settings sent to the user agent depend on cache timeout settings
						if($apiState['cache-timeout']!=0){
							// Cache control depends whether HTTP authentication is used or not
							if($this->state->data['http-authentication']==true){
								header('Cache-Control: private,max-age='.($apiState['last-modified']+$apiState['cache-timeout']-$this->state->data['request-time']).'');
							} else {
								header('Cache-Control: public,max-age='.($apiState['last-modified']+$apiState['cache-timeout']-$this->state->data['request-time']).'');
							}
							header('Expires: '.gmdate('D, d M Y H:i:s',($apiState['last-modified']+$apiState['cache-timeout'])).' GMT');
							header('Last-Modified: '.gmdate('D, d M Y H:i:s',$apiState['last-modified']).' GMT');
						} else {
							// When no cache is used, request tells specifically that
							header('Cache-Control: no-store;');
							header('Expires: '.gmdate('D, d M Y H:i:s',$this->state->data['request-time']).' GMT');
							header('Last-Modified: '.$apiState['last-modified'].' GMT');
						}
						
						// If custom header was assigned, it is added
						if($apiState['custom-header']){
							header($apiState['custom-header']);
						}
						
						// Removing all Pragma headers if server has set them
						header_remove('Pragma');
					
					// CONTENT TYPE HEADERS
					
						// If content type was set in the request then that is used for content type
						if($apiState['content-type-header']){
							// UTF-8 is always used for returned data
							header('Content-Type: '.$apiState['content-type-header'].';charset=utf-8');
						} else {
							// Data is echoed/printed based on return data type formatting with the proper header
							switch($apiState['return-type']){
								case 'json':
									header('Content-Type: application/json;charset=utf-8;');
									break;
								case 'xml':
									header('Content-Type: text/xml;charset=utf-8;');
									break;
								case 'html':
									header('Content-Type: text/html;charset=utf-8');
									break;
								case 'rss':
									header('Content-Type: application/rss+xml;charset=utf-8;');
									break;
								case 'csv':
									header('Content-Type: text/csv;charset=utf-8;');
									break;
								case 'js':
									header('Content-Type: application/javascript;charset=utf-8');
									break;
								case 'css':
									header('Content-Type: text/css;charset=utf-8');
									break;
								case 'vcard':
									header('Content-Type: text/vcard;charset=utf-8');
									break;
								default:
									// Every other case assumes text/plain response from server
									header('Content-Type: text/plain;charset=utf-8');
									break;
							}
						}
					
					// OUTPUT COMPRESSION
					
						// Gathering output in buffer
						ob_start();
						
						// Since output was turned on, result is loaded into the output buffer
						echo $apiResult;
						
						// If output compression is turned on then the content is compressed
						if($this->state->data['output-compression']!=false){
							// Different compression options can be used
							switch($this->state->data['output-compression']){
								case 'deflate':
									// Notifying user agent of deflated output
									header('Content-Encoding: deflate');
									$apiResult=gzdeflate(ob_get_clean(),9);
									break;
								case 'gzip':
									// Notifying user agent of gzipped output
									header('Content-Encoding: gzip');
									// This tells proxies to store both compressed and uncompressed version
									header('Vary: Accept-Encoding');
									$apiResult=gzencode(ob_get_clean(),9);
									break;
								default:
									$apiResult=ob_get_clean();
									break;
							}
						} else {
							// Getting data from output buffer
							$apiResult=ob_get_clean();
						}
					
					// PUSHING TO USER AGENT
					
						// Current output content length
						$contentLength=strlen($apiResult);
						
						// Content length is defined that can speed up website requests, letting user agent to determine file size
						header('Content-Length: '.$contentLength); 
						
						// Data is only updated if logger is used
						if($useLogger){
							// Notifying logger of content length
							$this->apiLoggerData['content-length']=$contentLength;
						}
						
						// Data is returned to the user agent
						echo $apiResult;
						
						// Processing is done
						return true;
					
				} else {
					// Since result was not output it is simply returned
					return $apiResult;
				}
			
		}
		
	// RESULT CALLBACKS
	
		// It is possible to execute certain callbacks with the API based on what data is 
		// returned from API. It is possible to set headers with this method that will be
		// sent to returned output buffer. It is also possible to set and unset cookies and 
		// sessions. It is also possible to redirect the user agent with a callback. $data 
		// is the return data from the API and $logger and $returnType are defined from 
		// output() method that makes the call to apiCallbacks(). This method is private 
		// and cannot be used outside the class.
		// * data - Data array
		// * useLogger - If logger is used
		final private function apiCallbacks($data,$useLogger){
		
			// HEADERS
		
				// This sets a specific header
				if(isset($data['www-set-header'])){
					// It is possible to set multiple headers simultaneously
					if(is_array($data['www-set-header'])){
						foreach($data['www-set-header'] as $header){
							header($header);
						}
					} else {
						header($data['www-set-header']);
					}
				}
				
			// COOKIES AND SESSIONS
			
				// This adds cookie from an array of settings
				if(isset($data['www-set-cookie']) && is_array($data['www-set-cookie'])){
					// This is when a single cookie is created
					if(isset($data['www-set-cookie']['name'],$data['www-set-cookie']['value'])){
						$this->state->setCookie($data['www-set-cookie']['name'],$data['www-set-cookie']['value'],$data['www-set-cookie']);
					} else {
						foreach($data['www-set-cookie'] as $cookie){
							// Cookies require name and value to be set
							if(isset($cookie['name'],$cookie['value'])){
								$this->state->setCookie($cookie['name'],$cookie['value'],$cookie);
							}
						}
					}
				}
				
				// This unsets cookie in user agent
				if(isset($data['www-unset-cookie'])){
					// Multiple cookies can be unset simultaneously
					if(is_array($data['www-unset-cookie'])){
						foreach($data['www-unset-cookie'] as $cookie){
							$this->state->unsetCookie($cookie);
						}
					} else {
						$this->state->unsetCookie($data['www-unset-cookie']);
					}
				}

				// This adds a session
				if(isset($data['www-set-session'])){
					// This is when a single cookie is created
					if(isset($data['www-set-session']['name'],$data['www-set-session']['value'])){
						$this->state->setSession($data['www-set-session']['name'],$data['www-set-session']['value']);
					} else {
						// Session value must be an array
						foreach($data['www-set-session'] as $session){
							if(isset($session['name'],$session['value'])){
								$this->state->setSession($session['name'],$session['value']);
							}
						}
					}
				}
				
				// This unsets cookie in user agent
				// Multiple sessions can be unset simultaneously
				if(isset($data['www-unset-session'])){
					if(is_array($data['www-unset-session'])){
						foreach($data['www-unset-session'] as $session){
							$this->state->unsetSession($session);
						}
					} else {
						$this->state->unsetSession($data['www-unset-session']);
					}
				}
			
			// REDIRECTS
				
				// It is possible to re-direct API after submission
				if(isset($data['www-temporary-redirect'])){
					// Adding log entry
					if($useLogger){
						$this->apiLoggerData['response-code']=302;
						$this->apiLoggerData['temporary-redirect']=$data['www-temporary-redirect'];
					}
					// Redirection header
					header('Location: '.$data['www-temporary-redirect'],true,302);
				} elseif(isset($data['www-permanent-redirect'])){
					// Adding log entry
					if($useLogger){
						$this->apiLoggerData['response-code']=301;
						$this->apiLoggerData['permanent-redirect']=$data['www-permanent-redirect'];
					}
					// Redirection header
					header('Location: '.$data['www-permanent-redirect'],true,301);
				}
		
			// Processing complete
			return true;
		
		}
		
	// RESULT CONVERSIONS
	
		// This is a method that converts an array to an XML string. It can convert to both 
		// common XML as well as to RSS format. $apiResult is the data sent to the request. 
		// If $type is set to 'rss' then RSS formatting is used, otherwise a regular XML is 
		// returned. This is an internal method used by output() call.
		// * apiResult - data returned from API call
		final private function toXML($apiResult,$type=false){
			
			// Different XML header is used based on whether it is an RSS or not
			if($type=='rss'){
                $xml='<?xml version="1.0" encoding="utf-8"?><rss version="2.0">';
			} else {
                $xml='<?xml version="1.0" encoding="utf-8"?><www>';
			}
			// This is the recursive function used
			$xml.=$this->toXMLnode($apiResult);
			if(!$type){
				$xml.='</www>';
			} else {
				$xml.='</rss>';
			}
			return $xml;
				
		}
		
		// This is a helper method for toXML() method and is used to build an XML node. This 
		// method is private and is not used elsewhere.
		// * data - Data entity from array
		final private function toXMLnode($data){
			// By default the result is empty
			$return='';
			foreach($data as $key=>$val){
				// If element is an array then this function is called again recursively
				if(is_array($val)){
					// XML does not allow numeric nodes, so generic '<node>' is used
					if(is_numeric($key)){
						$return.='<node>';
					} else {
						$return.='<'.$key.'>';
					}
					// Recursive call
					$return.=$this->toXMLnode($val);
					if(is_numeric($key)){
						$return.='</node>';
					} else {
						$return.='</'.$key.'>';
					}
				} else {
					// XML does not allow numeric nodes, so generic '<node>' is used
					if(is_numeric($key)){
						// Data is filtered for special characters
						$return.='<node>'.htmlspecialchars($val).'</node>';
					} else {
						$return.='<'.$key.'>'.htmlspecialchars($val).'</'.$key.'>';
					}
				}
			}
			// Returning the snippet
			return $return;
		}
		
		// This method converts an array to CSV format, based on the structure of the array. 
		// It uses tabs as a column separator and separates values by commas, if sub-arrays 
		// are used. $apiResult is the data sent by output() method.
		// * apiResult - data returned from API call
		final private function toCSV($apiResult){
			
			// Resulting rows are stored in this value
			$result=array();
			
			// First element of the array is output
			$tmp=array_slice($apiResult,0,1,true);
			$first=array_shift($tmp);
			
			// If the first array element is also an array then multidimensional CSV will be output
			if(is_array($first)){
			
				// System assumes that in a multidimensional array the keys are the column names
				$result[]=implode("\t",array_keys($first));
				
				// Rows will be processed as a result
				foreach($apiResult as $subResult){		
					foreach($subResult as $key=>$subSubResult){
						// If result is still an array, then the values are imploded with commas
						if(is_array($subSubResult)){
							$subSubResult=implode(',',$subSubResult);
						}
						// Potential characters will be replaced
						$subResult[$key]=str_replace(array("\n","\t","\r"),array('\n','\t',''),$subSubResult);
					}
					// Rows are separated with a tab character
					$result[]=implode("\t",$subResult);
				}
				
			} else {
			
				// Since first element was not an array, it is assumed that other rows are not either
				foreach($apiResult as $subResult){
					// If other rows are an array, then the result is imploded with a comma
					if(is_array($subResult)){
						$result[]=str_replace(array("\n","\t","\r"),array('\n','\t',''),implode(',',$subResult));
					} else {
						$result[]=str_replace(array("\n","\t","\r"),array('\n','\t',''),$subResult);
					}
				}
				
			}
			
			// Result is imploded and returned
			return implode("\n",$result);
			
		}
		
		// This looks at the input data from $apiResult and tries to determine if the result 
		// was a success or not. It considers response code namespace 5XX as a successful 
		// call and considers everything else a failure.
		// * apiResult - data returned from API call
		final private function toBinary($apiResult){
			// Based on the returned array, system 'assumes' whether the action was a success or not
			if((isset($apiResult['www-response-code']) && $apiResult['www-response-code']>=500) || (!isset($apiResult['www-response-code']) && !empty($apiResult))){
				return 1;
			} else {
				return 0;
			}
		}
		
		// This attempts to convert the data array of $apiResult to INI format. It handles 
		// also subarrays and other possible conditions in the array.
		// * apiResult - data returned from API call
		final private function toINI($apiResult){
			
			// Rows of INI file are stored in this variable
			$result=array();
			
			// Every array value is parsed separately
			foreach($apiResult as $key=>$value){
				// Separate handling based on whether the value is an array or not
				if(is_array($value)){
					// If value is an array then INI group is created for the value
					$result[]='['.$key.']';
					// All of the group values are output
					foreach($value as $subkey=>$subvalue){
						// If this sub-value is another array then it is imploded with commas
						if(is_array($subvalue)){
							foreach($subvalue as $subsubkey=>$subsubvalue){
								// If another array is set as a value
								if(is_array($subsubvalue)){
									foreach($subsubvalue as $k=>$v){
										if(is_array($v)){
											$subsubvalue[$k]=str_replace('"','\"',serialize($v));
										} else {
											$subsubvalue[$k]=str_replace('"','\"',$v);
										}
									}
									$result[]=preg_replace('/[^a-zA-Z0-9]/i','',$subkey).'['.preg_replace('/[^a-zA-Z0-9]/i','',$subsubkey).']="'.implode(',',$subsubvalue).'"';
								} else {
									$result[]=preg_replace('/[^a-zA-Z0-9]/i','',$subkey).'['.preg_replace('/[^a-zA-Z0-9]/i','',$subsubkey).']="'.str_replace('"','\"',$subsubvalue).'"';
								}
							}
						} else {
							// If the value was not an array, then value is simply output in INI format
							if(is_numeric($subvalue)){
								$result[]=preg_replace('/[^a-zA-Z0-9]/i','',$subkey).'='.$subvalue;
							} else {
								$result[]=preg_replace('/[^a-zA-Z0-9]/i','',$subkey).'="'.str_replace('"','\"',$subvalue).'"';
							}
						}
					}
				} else {
					// If the value was not an array, then value is simply output in INI format
					if(is_numeric($value)){
						$result[]=preg_replace('/[^a-zA-Z0-9]/i','',$key).'='.$value;
					} else {
						$result[]=preg_replace('/[^a-zA-Z0-9]/i','',$key).'="'.str_replace('"','\"',$value).'"';
					}
				}
			}
			
			// Result is imploded into line-breaks for INI format
			return implode("\n",$result);
			
		}
		
	// ENCRYPTION AND DECRYPTION
	
		// This method uses API class internal encryption function to encrypt $data string with 
		// a key and a secret key (if set). If only $key is set, then ECB mode is used for 
		// Rijndael encryption.
		// * data - data to be encrypted
		// * key - key used for encryption
		// * secretKey - used for calculating initialization vector (IV)
		final public function encryptRijndael256($data,$key,$secretKey=false){
			if($secretKey){
				return base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256,md5($key),$data,MCRYPT_MODE_CBC,md5($secretKey)));
			} else {
				return base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256,md5($key),$data,MCRYPT_MODE_ECB));
			}
		}
		
		// This will decrypt Rijndael encoded data string, set with $data. $key and $secretKey 
		// should be the same that they were when the data was encrypted.
		// * data - data to be decrypted
		// * key - key used for decryption
		// * secretKey - used for calculating initialization vector (IV)
		final public function decryptRijndael256($data,$key,$secretKey=false){
			if($secretKey){
				return trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256,md5($key),base64_decode($data),MCRYPT_MODE_CBC,md5($secretKey)));
			} else {
				return trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256,md5($key),base64_decode($data),MCRYPT_MODE_ECB));
			}
		}

		
	// CACHE AND BUFFER
	
		// This method unsets all cache that has been stored with a specific tag keyword. 
		// $tags variable can both be a string or an array of keywords. Every cache related 
		// to those keywords will be removed.
		// * tags - an array or comma separated list of tags that the cache was stored under
		final public function unsetTaggedCache($tags){
			// Multiple tags can be removed at the same time
			if(!is_array($tags)){
				$tags=explode(',',$tags);
			}
			foreach($tags as $tag){
				// If this tag has actually been used, it has a file in the filesystem
				if(file_exists($this->state->data['system-root'].'filesystem'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'tags'.DIRECTORY_SEPARATOR.md5($tag).'.tmp')){
					// Tag file can have links to multiple cache files
					$links=explode("\n",file_get_contents($this->state->data['system-root'].'filesystem'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'tags'.DIRECTORY_SEPARATOR.md5($tag).'.tmp'));
					foreach($links as $link){
						// This deletes cache file or removes if from APC storage
						$this->unsetCache($link);
					}
					// Removing the tag link file itself
					unlink($this->state->data['system-root'].'filesystem'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'tags'.DIRECTORY_SEPARATOR.md5($tag).'.tmp');
				}
			}
			return true;
		}
		
		// This method is used to clear current API command buffer. This is an optimization 
		// method and should be used only of a lot of API calls are made that might fill the 
		// memory allocated to PHP. What this method does is that it tells API object to empty 
		// the internal variable that stores the results of all API calls that have already been 
		// sent to API.
		final public function clearBuffer(){
			$this->commandBuffer=array();
			return true;
		}
		
		// This method returns currently existing cache for currently executed API call, if it 
		// exists. This allows you to always load cache from system in case a new response cannot 
		// be generated. It returns cache with the key $key.
		// * key - Current API index
		final public function getExistingCache($key){
			if(isset($this->cacheIndex[$key])){
				return $this->getCache($this->cacheIndex[$key]);
			} else {
				return false;
			}
		}
		
		// If cache exists for currently executed API call, then this method returns the UNIX 
		// timestamp of the time when that cache was written. It returns cache timestamp with 
		// the key $key.
		// * key - Current API index
		final public function getExistingCacheTime($key){
			if(isset($this->cacheIndex[$key])){
				return $this->cacheTime($this->cacheIndex[$key]);
			} else {
				return false;
			}
		}
		
		// This method can be used to store cache for whatever needs by storing $key and 
		// giving it a value of $value. Cache tagging can also be used with custom tag by 
		// sending a keyword with $tags or an array of keywords.
		// * keyUrl - Key or address where cache should be stored
		// * value - Value to be stored
		// * custom - Whether the stored cache is called within MVC classes
        // * tags - Tags that are attached to cache
		final public function setCache($keyAddress,$value,$custom=false,$tags=false){
			// User cache does not have an address
			if($custom){
				// User cache location
				$keyAddress=__ROOT__.'filesystem'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'custom'.DIRECTORY_SEPARATOR.md5($keyAddress).'.tmp';
				// If tag is attached to cache
				if($tags){
					if(!is_array($tags)){
                        $tags=explode(',',$tags);
					}
					foreach($tags as $t){
						if(!file_put_contents($this->state->data['system-root'].'filesystem'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'tags'.DIRECTORY_SEPARATOR.md5($t).'.tmp',$keyAddress."\n",FILE_APPEND)){
							trigger_error('Cannot store cache tag at '.$keyAddress,E_USER_ERROR);
						}
					}
				}
			}
			// Testing for APC presence
			if($this->apc){
				// Storing the value in APC storage
				if(!apc_store($keyAddress,$value)){
					trigger_error('Cannot store file cache in APC at '.$keyAddress,E_USER_ERROR);
				}
				// APC requires additional field to store the timestamp of cache
				apc_store($keyAddress.'-time',time());
			} else {
				// Attempting to write cache in filesystem
				if(!file_put_contents($keyAddress,serialize($value))){
					trigger_error('Cannot store file cache at '.$keyAddress,E_USER_ERROR);
				}
			}
			return true;
		}
		
		// This method fetches data from cache based on cache keyword $key, if cache exists. 
		// This should be the same keyword that was used in setCache() method, when storing cache.
		// * keyAddress - Address to store cache at
		final public function getCache($keyAddress,$custom=false){
			// User cache does not have an address
			if($custom){
				$keyAddress=__ROOT__.'filesystem'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'custom'.DIRECTORY_SEPARATOR.md5($keyAddress).'.tmp';
			}
			// Testing for APC presence
			if($this->apc){
				return apc_fetch($keyAddress);
			} else {
				// Testing if file exists
				if(file_exists($keyAddress)){
					return unserialize(file_get_contents($keyAddress));
				} else {
					return false;
				}
			}
		}
		
		// This function returns the timestamp of when the cache of keyword $key, was created, 
		// if such a cache exists.
		// * keyAddress - Address to store cache at
		final public function cacheTime($keyAddress,$custom=false){
			// User cache does not have an address
			if($custom){
				$keyAddress=__ROOT__.'filesystem'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'custom'.DIRECTORY_SEPARATOR.md5($keyAddress).'.tmp';
			}
			// Testing for APC presence
			if($this->apc){
				if(apc_exists(array($keyAddress,$keyAddress.'-time'))){
					return apc_fetch($keyAddress.'-time');
				} else {
					return false;
				}
			} else {
				// Testing if cache file exists
				if(file_exists($keyAddress)){
					return filemtime($keyAddress);
				} else {
					return false;
				}
			}
		}
		
		// This method removes cache that was stored with the keyword $key, if such a cache exists.
		// * keyAddress - Address where cache is stored
		final public function unsetCache($keyAddress,$custom=false){
			// User cache does not have an address
			if($custom){
				$keyAddress=__ROOT__.'filesystem'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'custom'.DIRECTORY_SEPARATOR.md5($keyAddress).'.tmp';
			}
			// Testing for APC presence
			if($this->apc){
				// Testing if key exists
				if(apc_exists($keyAddress)){
					apc_delete($keyAddress);
					apc_delete($keyAddress.'-time');
					return true;
				} else {
					return false;
				}
			} else {
				// Testing if cache file exists
				if(file_exists($keyAddress)){
					return unlink($keyAddress);
				} else {
					return false;
				}
			}
		}
	
	// INTERNAL LOGGING
	
		// This method attempts to write an entry to internal log. Log entry is stored with 
		// a $key and entry itself should be the $data. $key is needed to easily find the 
		// log entry later on.
		// * key - Descriptive key that the log entry will be stored under
		// * data - Data contained in the entry
		final public function internalLogEntry($key,$data=false){
			// Only applies if internal logging is turned on
			if($this->internalLogging && ((in_array('*',$this->internalLogging) && !in_array('!'.$key,$this->internalLogging)) || in_array($key,$this->internalLogging))){
				// Preparing a log entry object
				$entry=array($key=>$data);
				// Adding log entry to log array
				$this->internalLog[]=$entry;
				// Unsetting log entry container
				unset($entry);
				return true;
			} else {
				return false;
			}
		}
		
	// PERFORMANCE LOGGING
	
		// This method is a timer that can be used to grade performance within the system. 
		// When this method is called with some $key first, it will start the timer and write 
		// an entry to log about it. If the same $key is called again, then a log entry is 
		// created with the amount of microseconds that have passed since the last time this 
		// method was called with this $key.
		// * key - Identifier for splitTime group
		final public function splitTime($key='api'){
			// Checking if split time exists
			if(isset($this->splitTimes[$key])){
				$this->internalLogEntry('splitTime for ['.$key.']','Seconds since last call: '.number_format((microtime(true)-$this->splitTimes[$key]),6));
			} else {
				$this->internalLogEntry('splitTime for ['.$key.']','Seconds since last call: 0.000000 seconds');
			}
			// Setting new microtime
			$this->splitTimes[$key]=microtime(true);
			// Returning current microtime
			return $this->splitTimes[$key];
		}
		
	// DATA HANDLING
	
		// This helper method is used to sort an array (and sub-arrays) based on keys. It 
		// essentially applies ksort() method recursively to an array.
		// * array - Array to be sorted
		final private function ksortArray($data){
			// Method is based on the current data type
			if(is_array($data)){
				// Sorting the current array
				ksort($data);
				// Sorting every sub-array, if it is one
				$keys=array_keys($data);
				$keySize=sizeOf($keys);
				for($i=0;$i<$keySize;$i++){
					$data[$keys[$i]]=$this->ksortArray($data[$keys[$i]]);
				}
			}
			return $data;
		}
	
}
	
?>