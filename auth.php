<?php
//var_dump($_SERVER['REQUEST_METHOD'],$_SERVER['PATH_INFO']); die();

class PHP_API_AUTH {
	
	protected $tokenio;

	public function __construct($config) {
		extract($config);
		
		$verb = isset($verb)?$verb:null;
		$path = isset($path)?$path:null;
		$username = isset($username)?$username:null;
		$password = isset($password)?$password:null;
		$token = isset($token)?$token:null;
		$authenticator = isset($authenticator)?$authenticator:null;
		
		$method = isset($method)?$method:null;
		$request = isset($request)?$request:null;
		$post = isset($post)?$post:null;
		
		$time = isset($time)?$time:null;
		$leeway = isset($leeway)?$leeway:null;
		$ttl = isset($ttl)?$ttl:null;
		$algorithm = isset($algorithm)?$algorithm:null;
		$secret = isset($secret)?$secret:null;

		// defaults
		if (!$verb) {
			$verb = 'POST';
		}
		if (!$path) {
			$path = '';
		}
		if (!$username) {
			$username = 'username';
		}
		if (!$password) {
			$password = 'password';
		}
		if (!$token) {
			$token = 'token';
		}
		
		if (!$method) {
			$method = $_SERVER['REQUEST_METHOD'];
		}
		if (!$request) {
			$request = isset($_SERVER['PATH_INFO'])?$_SERVER['PATH_INFO']:'';
			if (!$request) {
				$request = isset($_SERVER['ORIG_PATH_INFO'])?$_SERVER['ORIG_PATH_INFO']:'';
			}
		}
		if (!$post) {
			$post = 'php://input';
		}
		
		if (!$time) {
			$time = time();
		}
		if (!$leeway) {
			$leeway = 5;
		}
		if (!$ttl) {
			$ttl = 30;
		}
		if (!$algorithm) {
			$algorithm = 'HS256';
		}

		$request = trim($request,'/');
		
		$this->settings = compact('verb', 'path', 'username', 'password', 'token', 'authenticator', 'method', 'request', 'post', 'time', 'leeway', 'ttl', 'algorithm', 'secret');
	}


	protected function retrieveInput($post) {
		$input = (object)array();
		$data = trim(file_get_contents($post));
		if (strlen($data)>0) {
			if ($data[0]=='{') {
				$input = json_decode($data);
			} else {
				parse_str($data, $input);
				$input = (object)$input;
			}
		}
		return $input;
	}

	protected function generateToken($claims,$time,$ttl,$algorithm,$secret) {
		$algorithms = array('HS256'=>'sha256','HS384'=>'sha384','HS512'=>'sha512');
		$header = array();
		$header['typ']='JWT';
		$header['alg']=$algorithm;
		$token = array();
		$token[0] = rtrim(strtr(base64_encode(json_encode((object)$header)),'+/','-_'),'=');
		$claims['iat'] = $time;
		$claims['exp'] = $time + $ttl;
		$token[1] = rtrim(strtr(base64_encode(json_encode((object)$claims)),'+/','-_'),'=');
		if (!isset($algorithms[$algorithm])) return false;
		$hmac = $algorithms[$algorithm];
		$signature = hash_hmac($hmac,"$token[0].$token[1]",$secret,true);
		$token[2] = rtrim(strtr(base64_encode($signature),'+/','-_'),'=');
		return implode('.',$token);
	}

	protected function getVerifiedClaims($token,$time,$leeway,$ttl,$algorithm,$secret) {
		$algorithms = array('HS256'=>'sha256','HS384'=>'sha384','HS512'=>'sha512');
		if (!isset($algorithms[$algorithm])) return false;
		$hmac = $algorithms[$algorithm];
		$token = explode('.',$token);
		if (count($token)<3) return false;
		$header = json_decode(base64_decode(strtr($token[0],'-_','+/')),true);
		if (!$secret) return false;
		if ($header['typ']!='JWT') return false;
		if ($header['alg']!=$algorithm) return false;
		$signature = bin2hex(base64_decode(strtr($token[2],'-_','+/')));
		if ($signature!=hash_hmac($hmac,"$token[0].$token[1]",$secret)) return false;
		$claims = json_decode(base64_decode(strtr($token[1],'-_','+/')),true);
		if (!$claims) return false;
		if (isset($claims['nbf']) && $time+$leeway<$claims['nbf']) return false;
		if (isset($claims['iat']) && $time+$leeway<$claims['iat']) return false;
		if (isset($claims['exp']) && $time-$leeway>$claims['exp']) return false;
		if (isset($claims['iat']) && !isset($claims['exp'])) {
			if ($time-$leeway>$claims['iat']+$ttl) return false;
		}
		return $claims;
	}

	public function executeCommand() {
		extract($this->settings);
		$no_session = $authenticator && $secret; 
		if (!$no_session) {
			ini_set('session.cookie_httponly', 1);
			session_start();
			if (!isset($_SESSION['csrf'])) {
				if (function_exists('random_int')) $_SESSION['csrf'] = random_int(0,PHP_INT_MAX);
				else $_SESSION['csrf'] = rand(0,PHP_INT_MAX);
			}
		}
		if ($method==$verb && trim($path,'/')==$request) {
			$input = $this->retrieveInput($post);
			if ($authenticator && isset($input->$username) && isset($input->$password)) {
				$authenticator($input->$username,$input->$password);
				if ($no_session) {
					$this->tokenio = $this->generateToken($_SESSION,$time,$ttl,$algorithm,$secret);
					echo json_encode($this->tokenio);
				} else {
					session_regenerate_id();
					$this->tokenio = $_SESSION['csrf'];
					echo json_encode($this->tokenio);
				}
			} elseif ($secret && isset($input->$token)) {
				$claims = $this->getVerifiedClaims($input->$token,$time,$leeway,$ttl,$algorithm,$secret);
				if ($claims) {
					foreach ($claims as $key=>$value) {
						$_SESSION[$key] = $value;
					}
					session_regenerate_id();
					$this->tokenio = $_SESSION['csrf'];
					echo json_encode($this->tokenio);
				}
			} else {
				if (!$no_session) {
					session_destroy();
				}
			}
			return true;
		}
		return false;
	}

	public function gettoken(){
		return $this->tokenio;
	}
}
