<?php

/**
 * Cheryl 4.0
 *
 * 2003 - 2016 Devin Smith
 * https://github.com/arzynik/cheryl
 *
 * Cheryl is a web based file manager for the modern web using
 * PHP5 and AngularJS.
 *
 */

namespace Cheryl;

class Cheryl {
	private static $_cheryl;

	private $defaultConfig = array(
		// the admin username nad password to access all features. if set to blank, all users will have access to all enabled features
		// array of users. this can be overwridden by custom user clases
		'users' => array(
			array(
				'username' => 'admin',
				'password' => '',
				'permissions' => 'all' // if set to all, all permissions are enabled. even new features addedd in the future
			)
		),
		'authentication' => 'simple',  // simple: users are stored in the users array. mysql: uses a mysqli interface. pdo: uses pdo interface. see examples,
		'root' => 'files', // the folder you want users to browse
		'includes' => 'Cheryl', // path to look for additional libraries. leave blank if you dont know
		'readonly' => false, // if true, disables all write features, and doesnt require authentication
		'features' => array(
			'snooping' => false, // if true, a user can browse filters behind the root directory, posibly exposing secure files. not reccomended
			'recursiveBrowsing' => true, // if true, allows a simplified view that shows all files recursivly in a directory. with lots of files this can slow it down
		),
		// files to hide from view
		'hiddenFiles' => array(
			'.DS_Store',
			'desktop.ini',
			'.git',
			'.svn',
			'.hg',
			'.thumb'
		),
		'libraries' => array(
			'type' => 'remote'
		),
		'recursiveDelete' => true // if true, will allow deleting of unempty folders
	);

	public $features = array(
		'rewrite' => false,
		'userewrite' => null,
		'json' => false,
		'gd' => false,
		'exif' => false,
		'imlib' => false,
		'imcli' => false
	);

	public $authed = false;


	public static function init($config = null) {
		if (!self::$_cheryl) {
			new Cheryl($config);
		}
		return self::$_cheryl;
	}

	public function __construct($config = null) {
		if (!self::$_cheryl) {
			self::$_cheryl = $this;
		}

		if (is_object($config)) {
			$config = (array)$config;
		} elseif(is_array($config)) {
			$config = $config;
		} else {
			$config = [];
		}

		$this->_tipsy = \Tipsy\Tipsy::app();

		$this->config = array_merge($this->defaultConfig, $this->tipsy()->config()['cheryl']);
		$this->config = array_merge($this->config, $config);

		$this->_digestRequest();
		$this->_setup();
		$this->_authenticate();

		$self = $this;

		$this->tipsy()->service('Auth', function() use ($self) {
			$service = [
				// set readonly to true if a readonly page should have access
				check => function($readonly = false, $permission = null) use ($self) {
					if (!$self->authed && (($readonly && !$self->config['readonly']) || (!$readonly))) {
						$res = false;
					} else {
						$res = true;
					}

					if ($res == false) {
						http_response_code(401);
						echo json_encode([
							'status' => false,
							'message' => 'not authenticated'
						]);
						exit;
					}
				}

			];
			return $service;
		});
	}

	// method to grab object from static calls
	public static function me() {
		return self::$_cheryl;
	}

	public static function start() {
		$self = self::me();
		$self->tipsy()->request()->path($self->tipsy()->request()->request()['__p']);

		$self->tipsy()
			->get('logout', function() use ($self) {
				@session_destroy();
				@session_regenerate_id();
				@session_start();

				echo json_encode([
					'status' => true,
					'message' => 'logged out'
				]);
			})

			->post('login', function() use ($self) {
				$user = User::login(
					$self->tipsy()->request()->request()['username'],
					$self->tipsy()->request()->request()['password']
				);

				if ($user) {
					$self->user = $user;
					$self->authed = $_SESSION['cheryl-authed'] = true;
					$_SESSION['cheryl-username'] = $self->user->username;
					echo json_encode([
						'status' => true,
						'message' => 'logged in'
					]);

				} else {
					echo json_encode([
						'status' => false,
						'message' => 'failed to log in'
					]);
				}
			})

			->get('config', function() use ($self) {
				echo json_encode([
					'status' => true,
					'authed' => $self->authed,
					'user' => $self->user ? $self->user->exports() : ''
				]);
			})

			->get('ls', function($Auth) use ($self) {
				$Auth->check(true);

				if (!$self->requestDir) {
					http_response_code(404);
					return;
				}

				$res = $self->storageAdapter()->ls($self->requestDir, $self->request['filters']);
				echo json_encode($res);
			})

			->get('dl', function($Auth) use ($self) {
				$Auth->check(true);

				if (!$self->requestDir) {
					http_response_code(404);
					return;
				}

				$self->storageAdapter()->getFile($self->requestDir, true);
			})

			->post('ul', function() use ($self) {
				$self->_takeFile();
			})

			->get('vw', function($Auth) use ($self) {
				$Auth->check(true);

				if (!$self->requestDir) {
					http_response_code(404);
					return;
				}

				$self->storageAdapter()->getFile($self->requestDir, false);
			})

			->get('rm', function($Auth) use ($self) {
				$Auth->check(false);

				if ($self->config['readonly'] || !$self->user->permission('delete', $self->requestDir)) {
					echo json_encode([
						'status' => false,
						'message' => 'no permission'
					]);
					exit;
				}

				$status = $self->storageAdapter()->deleteFile($self->requestDir);

				echo json_encode([
					'status' => $status
				]);
			})

			->get('rn', function() use ($self) {
				$self->_renameFile();
			})

			->get('mk', function() use ($self) {
				$self->_makeFile();
			})

			->post('sv', function($Auth) use ($self) {
				$Auth->check(false);

				if ($self->config['readonly'] || !$self->user->permission('save', $self->requestDir)) {
					echo json_encode([
						'status' => false,
						'message' => 'no permission'
					]);
					exit;
				}

				$status = $self->storageAdapter()->saveFile($self->requestDir, $this->request['c']);

				echo json_encode([
					'status' => $status
				]);
			})

			->otherwise(function($View) {
				$View->display('cheryl');
			});

		$self->tipsy()->run();
	}

	public function _setup() {

		$this->features = (object)$this->features;

		if (file_exists($this->config['includes'])) {
			// use include root at script level
			$this->config['includes'] = realpath($this->config['includes']).DIRECTORY_SEPARATOR;

		} elseif (file_exists(dirname(__FILE__).DIRECTORY_SEPARATOR.$this->config['includes'])) {
			// use include root at lib level
			$this->config['includes'] = dirname(__FILE__).DIRECTORY_SEPARATOR.$this->config['includes'].DIRECTORY_SEPARATOR;

		} else {
			// use current path
			$this->config['includes'] = realpath(__FILE__).DIRECTORY_SEPARATOR;
		}

		if (!$this->config['root']) {
			$this->config['root'] = dirname($_SERVER['SCRIPT_FILENAME']).DIRECTORY_SEPARATOR;
		} else {
			$this->config['root'] = dirname($_SERVER['SCRIPT_FILENAME']).DIRECTORY_SEPARATOR.$this->config['root'].DIRECTORY_SEPARATOR;

			if (!file_exists($this->config['root'])) {
				@mkdir($this->config['root']);
				@chmod($this->config['root'], 0777);
			}
		}

		if ((function_exists('apache_get_modules') && in_array('mod_rewrite', apache_get_modules())) || getenv('HTTP_MOD_REWRITE') == 'On') {
			$this->features->rewrite = true;
		}

		if (function_exists('json_decode')) {
			$this->features->json = true;
		}

		if (function_exists('exif_read_data')) {
			$this->features->exif = true;
		}

		if (function_exists('getimagesize')) {
			$this->features->gd = true;
		}

		if (function_exists('Imagick::identifyImage')) {
			$this->features->imlib = true;
		}

		if (!$this->features->imlib) {
			$o = shell_exec('identify -version 2>&1');
			if (!strpos($o, 'not found')) {
				$this->features->imcli = 'identify';
			} elseif (file_exists('/usr/local/bin/identify')) {
				$this->features->imcli = '/usr/local/bin/identify';
			} elseif(file_exists('/usr/bin/identify')) {
				$this->features->imcli = '/usr/bin/identify';
			} elseif(file_exists('/opt/local/bin/identify')) {
				$this->features->imcli = '/opt/local/bin/identify';
			} elseif(file_exists('/bin/identify')) {
				$this->features->imcli = '/bin/identify';
			} elseif(file_exists('/usr/bin/identify')) {
				$this->features->imcli = '/usr/bin/identify';
			}
		}
	}

	public function _authenticate() {
		if (php_sapi_name() == 'cli') {
			return $this->authed = true;
		}

		if (!User::users()) {
			// allow anonymouse access. ur crazy!
			return $this->authed = true;
		}

		session_start();

		if ($_SESSION['cheryl-authed']) {
			$this->user = new User($_SESSION['cheryl-username']);
			return $this->authed = true;
		}
	}

	public function _digestRequest() {
		/*
		if ($this->request['__p']) {
			// we have a page param result
			$url = explode('/',$this->request['__p']);
			$this->features->userewrite = false;

		} else {
			$url = false;
		}
		*/

		$this->request = $this->tipsy()->request()->request();

		// sanatize file/directory requests
		if ($this->request['_d']) {
			$this->request['_d'] = str_replace('/',DIRECTORY_SEPARATOR, $this->request['_d']);
			if ($this->config['features']['snooping']) {
				// just allow them to enter any old damn thing
				$this->requestDir = $this->config['root'].$this->request['_d'];
			} else {
				$this->requestDir = preg_replace('/\.\.\/|\.\//i','',$this->request['_d']);
				//$this->requestDir = preg_replace('@^'.DIRECTORY_SEPARATOR.basename(__FILE__).'@','',$this->requestDir);
				$this->requestDir = $this->config['root'].$this->requestDir;
			}

			if (file_exists($this->requestDir)) {
				$this->requestDir = dirname($this->requestDir).DIRECTORY_SEPARATOR.basename($this->requestDir);
			} else {
				$this->requestDir = null;
			}
		}

		// sanatize filename
		if ($this->request['_n']) {
			$this->requestName = preg_replace('@'.DIRECTORY_SEPARATOR.'@','',$this->request['_n']);
		}
	}

	public function _takeFile() {
		if (!$this->authed) {
			echo json_encode(array('status' => false, 'message' => 'not authenticated'));
			exit;
		}
		if ($this->config['readonly'] || !$this->user->permission('upload', $this->requestDir)) {
			echo json_encode(array('status' => false, 'message' => 'no permission'));
			exit;
		}

		foreach ($_FILES as $file) {
			move_uploaded_file($file['tmp_name'],$this->requestDir.DIRECTORY_SEPARATOR.$file['name']);
		}

		echo json_encode(array('status' => true));
	}

	public function _renameFile() {
		if (!$this->authed) {
			echo json_encode(array('status' => false, 'message' => 'not authenticated'));
			exit;
		}
		if ($this->config['readonly'] || !$this->user->permission('rename', $this->requestDir)) {
			echo json_encode(array('status' => false, 'message' => 'no permission'));
			exit;
		}

		if (@rename($this->requestDir, dirname($this->requestDir).DIRECTORY_SEPARATOR.$this->requestName)) {
			$status = true;
		} else {
			$status = false;
		}

		echo json_encode(array('status' => $status, 'name' => $this->requestName));
	}

	public function _makeFile() {
		if (!$this->authed) {
			echo json_encode(array('status' => false, 'message' => 'not authenticated'));
			exit;
		}
		if ($this->config['readonly'] || !$this->user->permission('create', $this->requestDir)) {
			echo json_encode(array('status' => false, 'message' => 'no permission'));
			exit;
		}

		if (@mkdir($this->requestDir.DIRECTORY_SEPARATOR.$this->requestName,0777)) {
			$status = true;
		} else {
			$status = false;
		}

		echo json_encode(array('status' => $status, 'name' => $this->requestName));
	}

	public static function iteratorFilter($current) {
        return !in_array(
            $current->getFileName(),
            self::me()->config['hiddenFiles'],
            true
        );
	}

	public function tipsy() {
		return $this->_tipsy;
	}

	public function storageAdapter() {
		if (!isset($this->_storageAdapter)) {
			switch ($this->config['storage']) {
				default:
				case 'local':
					$this->_storageAdapter = new File\Local\Adapter;
					break;
				case 'db':
					$this->_storageAdapter = new File\Db\Adapter;
					break;
				case 's3':
					throw new \Exception('todo');
					break;
			}
		}
		return $this->_storageAdapter;
	}
}


