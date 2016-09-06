<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Main extends CI_Controller {

	public function __construct(){
		  parent::__construct();
	}

	public function index($access = NULL){
		// comprobar IP del host
		if(strpos($_SERVER['REMOTE_ADDR'], "149.154.167.") === FALSE){ die(); }

		// iniciar variables
		$telegram = $this->telegram;
		$pokemon = $this->pokemon;
		$chat = $telegram->chat;
		$user = $telegram->user;

		$colores_full = [
			['Y' => 'amarillo', 'instinto', 'yellow'],
			['R' => 'rojo', 'valor', 'red'],
			['B' => 'azul', 'sabiduría', 'blue'],
		];
		$colores = array();
		foreach($colores_full as $c){
			foreach($c as $col){ $colores[] = $col; }
		}

		/*
		####################
		# Funciones de Oak #
		####################
		*/

		// Oak o otro usuario es añadido a una conversación
		if($telegram->is_chat_group() && $telegram->data_received() == "new_chat_participant"){
			$set = $pokemon->settings($chat->id, 'announce_welcome');
			$new = $telegram->new_user;

			if($new->id == $this->config->item("telegram_bot_id")){
				$count = $telegram->send->get_members_count();
				// Si el grupo tiene <= 5 usuarios, el bot abandona el grupo
				if(is_numeric($count) && $count <= 5){
					// A excepción de que lo agregue el creador
					if($telegram->user->id != $this->config->item('creator')){
						$this->analytics->event('Telegram', 'Join low group');
						$telegram->send->leave_chat();
						exit();
					}
				}
			}elseif($telegram->is_bot($new->username)){
				// Bot agregado al grupo. Yo no saludo bots :(
				exit();
			}

			$pknew = $pokemon->user($new->id);
			// El usuario nuevo es creador
			if($new->id == $this->config->item('creator')){
				$telegram->send
					->notification(TRUE)
					->reply_to(TRUE)
					->text("Bienvenido, jefe @duhow! Un placer tenerte aquí! :D")
				->send();
				exit();
			}elseif(!empty($pknew)){
				// Si el grupo es exclusivo a un color y el usuario es de otro color
				$teamonly = $pokemon->settings($chat->id, 'team_exclusive');
				if(!empty($teamonly) && $teamonly != $pknew->team){
					$this->analytics->event('Telegram', 'Spy enter group');
					$telegram->send
						->notification(TRUE)
						->reply_to(TRUE)
						->text("*¡SE CUELA UN TOPO!* @$pknew->username $pknew->team", TRUE)
					->send();

					// Kickear (por defecto TRUE)
					$kick = $pokemon->settings($chat->id, 'team_exclusive_kick');
					if($kick != FALSE){
						$telegram->send->kick($user->id, $chat->id);
					}
					exit();
				}

				$blacklist = $pokemon->settings($chat->id, 'blacklist');
				if(!empty($blacklist)){
					$blacklist = explode(",", $blacklist);
					$pknew_flags = $pokemon->user_flags($pknew->telegramid);
					foreach($blacklist as $b){
						if(in_array($b, $pknew_flags)){
							$this->analytics->event('Telegram', 'Join blacklist user', $b);
							$telegram->send->kick($user->id, $chat->id);
							exit();
						}
					}
				}
			}

			// Si el grupo no admite más usuarios...
			$nojoin = $pokemon->settings($chat->id, 'limit_join');
			if($nojoin == TRUE){
				$this->analytics->event('Telegram', 'Join limit users');
				$telegram->send->kick($user->id, $chat->id);
				exit();
			}

			// Si un usuario generico se une al grupo
			if($set != FALSE or $set === NULL){
				$custom = $pokemon->settings($chat->id, 'welcome');
				$text = 'Bienvenido al grupo, $nombre!' ."\n";
				if(!empty($custom)){ $text = json_decode($custom) ."\n"; }
				if(empty($pknew)){
					$text .= "Oye, ¿podrías decirme de que color eres?\n*Di: *_Soy ..._";
				}else{
					$emoji = ["Y" => "yellow", "B" => "blue", "R" => "red"];
					$text .= '$pokemon $nivel $equipo $valido';
				}

				if($new->id == $this->config->item("telegram_bot_id")){
					$text = "¡Buenas a todos, entrenadores!\n¡Un placer estar con todos vosotros! :D";
				}

				$this->analytics->event('Telegram', 'Join user');
				$repl = [
					'$nombre' => $new->first_name,
					'$apellidos' => $new->last_name,
					'$equipo' => ':heart-' .$emoji[$pknew->team] .':',
					'$team' => ':heart-' .$emoji[$pknew->team] .':',
					'$usuario' => "@" .$new->username,
					'$pokemon' => "@" .$pknew->username,
					'$nivel' => "L" .$pknew->lvl,
					'$valido' => ($pknew->verified ? ':green-check:' : ':warning:')
				];
				$text = str_replace(array_keys($repl), array_values($repl), $text);
				$telegram->send
					->notification(FALSE)
					->reply_to(TRUE)
					->text( $telegram->emoji($text) , TRUE)
				->send();
			}
			exit();
		}
		// mensaje de despedida
		elseif($telegram->is_chat_group() && $telegram->data_received() == "left_chat_participant"){
			//
		}

		// pillando a los h4k0rs
		elseif($telegram->text_contains(["fake GPS", "fake", "fakegps", "nox"])){
			if($telegram->user->id != $this->config->item("creator")){
			$this->analytics->event('Telegram', 'Talk cheating');
			$telegram->send
				->text("*(A)* *$chat->title* - $user->first_name @" .$user->username .":\n" .$telegram->text(), TRUE)
				->chat($this->config->item('creator'))
			->send();
			// $this->telegram->sendHTML("*OYE!* Si vas a empezar con esas, deberías dejar el juego. En serio, hacer trampas *NO MOLA*.");
			exit();
			}
		}elseif($telegram->text_has(["pole", "subpole", "bronce"], TRUE) or $telegram->text_command(["pole", "subpole"])){
			$this->analytics->event("Telegram", "Pole");
			$pole = $pokemon->settings($telegram->chat->id, 'pole');
			if($pole != NULL && $pole == FALSE){ return; }

			// Si está el Modo HARDCORE, la pole es cada hora. Si no, cada día.
			$timer = ($pokemon->settings($telegram->chat->id, 'pole_hardcore') ? "H" : "d");

			if(!empty($pole)){
				$pole = unserialize($pole);
				if(
					( $telegram->text_has("pole", TRUE) &&    date($timer) == date($timer, $pole[0]) ) or
					( $telegram->text_has("subpole", TRUE) && date($timer) == date($timer, $pole[1]) ) or
					( $telegram->text_has("bronce", TRUE) &&  date($timer) == date($timer, $pole[2]) )
				){
					return;  // Mismo dia? nope.
				}
			}
			$pole_user = unserialize($pokemon->settings($telegram->chat->id, 'pole_user'));
			$pkuser = $pokemon->user($telegram->user->id);

			if($telegram->text_has("pole", TRUE)){ // and date($timer) != date($timer, $pole[0])
				$pole = [time(), NULL, NULL];
				$pole_user = [$telegram->user->id, NULL, NULL];
				$action = "la *pole*";
				if($pkuser && $timer == "d"){ $pokemon->update_user_data($pkuser->telegramid, 'pole', ($pkuser->pole + 3)); }
			}elseif($telegram->text_has("subpole", TRUE) and date($timer) == date($timer, $pole[0]) and $pole_user[1] == NULL){
				if(in_array($telegram->user->id, $pole_user)){ return; } // Si ya ha hecho pole, nope.
				$pole[1] = time();
				$pole_user[1] = $telegram->user->id;
				$action = "la *subpole*";
				if($pkuser && $timer == "d"){ $pokemon->update_user_data($pkuser->telegramid, 'pole', ($pkuser->pole + 2)); }
			}elseif($telegram->text_has("bronce", TRUE) and date($timer) == date($timer, $pole[0]) and $pole_user[1] != NULL and $pole_user[2] == NULL){
				if(in_array($telegram->user->id, $pole_user)){ return; } // Si ya ha hecho sub/pole, nope.
				$pole[2] = time();
				$pole_user[2] = $telegram->user->id;
				$action = "el *bronce*";
				if($pkuser && $timer == "d"){ $pokemon->update_user_data($pkuser->telegramid, 'pole', ($pkuser->pole + 1)); }
			}else{
				exit();
			}

			$pokemon->settings($chat->id, 'pole', serialize($pole));
			$pokemon->settings($chat->id, 'pole_user', serialize($pole_user));
			$telegram->send->text($telegram->user->first_name ." ha hecho $action!", TRUE)->send();
			// $telegram->send->text("Lo siento " .$telegram->user->first_name .", pero hoy la *pole* es mía! :D", TRUE)->send();
			exit();
		}
		elseif($telegram->text_contains(["profe", "oak"]) && $telegram->text_has("dónde estás") && $telegram->words() <= 5){
			$telegram->send
				->notification(FALSE)
				// ->reply_to(TRUE)
				->text($telegram->emoji("Detrás de ti... :>"))
			->send();
			exit();
		}

		// comprobar estado del bot
		elseif($telegram->text_contains(["profe", "oak"]) && $telegram->text_has(["ping", "pong", "me recibe", "estás", "estás ahí"]) && $telegram->words() <= 4){
			/* if($telegram->is_chat_group() && $telegram->user->id != $this->config->item('creator')){
				if($this->is_shutup()){ exit(); }
			} */
			$this->analytics->event('Telegram', 'Ping');
			$telegram->send->text("Pong! :D")->send();
			exit();
		}

		// si el usuario existe, proceder a interpretar el mensaje
		if($pokemon->user_exists($user->id)){
			$this->_begin();
		}

		// Comando de información de registro para la gente que tanto lo spamea...
		elseif($telegram->text_has("/register", TRUE)){
			$this->analytics->event('Telegram', 'Register', 'command');
			$str = "Hola " .$telegram->user->first_name ."! Me podrías decir tu color?\n"
					."(*Soy* ...)";
			$telegram->send
				->notification(FALSE)
				->text($str, TRUE)
			->send();
			exit();
		}

		// guardar color de user
		elseif(
		    ($telegram->text_has(["Soy", "Equipo", "Team"]) && $telegram->text_has($colores)) or
		    ($telegram->text_has($colores) && $telegram->words() == 1)
		){
			if(!$pokemon->user_exists($user->id)){
				$text = trim(strtolower($telegram->last_word('alphanumeric-accent')));

				// Registrar al usuario si es del color correcto
				if($pokemon->register($user->id, $text) !== FALSE){
					$this->analytics->event('Telegram', 'Register', $text);

					$name = $user->first_name ." " .$user->last_name;
					$pokemon->update_user_data($user->id, 'fullname', $name);
					$pokemon->step($user->id, 'SETNAME'); // HACK Poner nombre con una palabra
					// enviar mensaje al usuario
					$telegram->send
						->notification(FALSE)
						->reply_to(TRUE)
						->text("Muchas gracias $user->first_name! Por cierto, ¿cómo te llamas *en el juego*? \n_(Me llamo...)_", TRUE)
					->send();
				}else{
					$this->analytics->event('Telegram', 'Register', 'wrong', $text);
					$telegram->send
						->notification(FALSE)
						->reply_to(TRUE)
						->text("No te he entendido bien...\n¿Puedes decirme sencillamente *soy rojo*, *soy azul* o *soy amarillo*?", TRUE)
					->send();
				}
			}
		}else{
			// ---------
		}
	}

	// interpretar mensajes de usuarios verificados
	function _begin(){
		// TODO hay que reducir la complejidad de esta bestialidad de funcion ^^
		$telegram = $this->telegram;
		$pokemon = $this->pokemon;
		$user = $telegram->user;
		$text = $telegram->text();

		$pokeuser = $pokemon->user($user->id);
		$step = $pokemon->step($user->id);

		// terminar si el usuario no esta verificado o esta en la blacklist
		if($pokemon->user_blocked($user->id)){ die(); }

		// Cancelar pasos en general.
		if($step != NULL && $telegram->text_has(["Cancelar", "Desbugear", "/cancel"], TRUE)){
			$pokemon->step($user->id, NULL);
			$this->telegram->send
				->notification(FALSE)
				->keyboard()->selective(FALSE)->hide()
				->text("Acción cancelada.")
			->send();
			exit();
		}

		if(!empty($step)){ $this->_step(); }

		/*
		##################
		# Comandos admin #
		##################
		*/

		// enviar broadcast a todos los grupos (solo creador)
		if($telegram->text_has("/broadcast", TRUE) && $user->id == $this->config->item('creator')){
			exit();
			$text = substr($text, strlen("/broadcast "));
			foreach($pokemon->get_groups() as $g){
				$res = $telegram->send
					->chat($g)
					->notification(TRUE)
					->text($text, TRUE)
				->send();
				var_dump($res);
			}
			exit();
		}
		elseif($telegram->text_has("/usercast", TRUE) && $user->id == $this->config->item('creator')){
			exit(); // TODO temporal
			$text = substr($text, strlen("/usercast "));
			// Cada 100 usuarios, enviar un mensaje de confirmación del progreso.
			$users = $pokemon->get_users(TRUE);
			$c = 0;
			foreach($users as $u){
				if($c % 100 == 0){
					$telegram->send
						->chat( $this->config->item('creator') )
						->notification(FALSE)
						->text("Enviados $c de " .count($users) ." (" .floor(($c / count($users)) * 100) .")")
					->send();
				}
				$telegram->send
					->chat($u)
					->notification(TRUE)
					->text($text, TRUE)
				->send();
				$c++;
			}
		}elseif($telegram->text_contains(["/block", "/unblock"], TRUE) && $user->id == $this->config->item('creator')){
			$user = NULL;
			if($telegram->has_reply){
				// if($telegram->reply_is_forward)
				$user = $telegram->reply_user->id;
			}elseif($telegram->words() == 2 && $telegram->text_mention()){
				// $user = $telegram->text_mention(); // --> to UID.
			}
			if(empty($user)){ exit(); }
			$pokemon->update_user_data($user, 'blocked', $telegram->text_contains("/block"));
		}
		// echar usuario del grupo
		elseif($telegram->text_has(["/kick", "/ban"], TRUE) && $telegram->is_chat_group()){
			$admins = $this->admins(TRUE);

			if(in_array($telegram->user->id, $admins)){ // Tiene que ser admin
				$kick = NULL;
				if($telegram->has_reply){
					$kick = $telegram->reply_user->id;
				}elseif($telegram->text_mention()){
					$kick = $telegram->text_mention(); // Solo el primero
					if(is_array($kick)){ $kick = key($kick); } // Get TelegramID
				}elseif($telegram->words() == 2){
					// Buscar usuario.
					$kick = $telegram->last_word();
					// Buscar si no en PKGO user DB.
				}
				if(($telegram->user->id == $this->config->item('creator')) or !in_array($kick, $admins)){ // Si es creador o no hay target a admins
					if($telegram->text_contains("kick")){
						$this->analytics->event('Telegram', 'Kick');
						$telegram->send->kick($kick, $telegram->chat->id);
					}elseif($telegram->text_contains("ban")){
						$this->analytics->event('Telegram', 'Ban');
						$telegram->send->ban($kick, $telegram->chat->id);
					}
				}
			}
		}
		// Votar kick de usuarios.
		elseif($telegram->text_has(["/votekick", "/voteban"], TRUE) && $telegram->is_chat_group()){
			// Si el usuario que convoca el comando es troll o tiene flags, no puede votar ni usarlo.
			if($pokemon->user_flags($user->id, ['troll', 'bot', 'hacks', 'spam', 'rager', 'ratkid'])){ exit(); }
		}
		// el bot explusa al emisor del mensaje
		elseif($telegram->text_has("/autokick", TRUE) && $telegram->is_chat_group()){
			$this->analytics->event('Telegram', 'AutoKick');
			$res = $telegram->send->kick($telegram->user->id, $telegram->chat->id);
			if(!$res){ $telegram->send->text("No puedo :(")->send(); }
		}
		// enviar lista de admins
		elseif(
			(
				( $telegram->text_has("lista") and $telegram->text_has(["admins", "admin", "administradores"]) and $telegram->words() <= 8 ) or
				$telegram->text_command(["adminlist", "admins"])
			) and
			$telegram->is_chat_group()
		){
			$admins = $telegram->get_admins($telegram->chat->id, TRUE);
			$teams = ["Y" => "yellow", "B" => "blue", "R" => "red"];
			$str = "";

			foreach($admins as $k => $a){
				if($a['status'] == 'creator'){
					unset($admins[$k]);
					array_unshift($admins, $a);
				}elseif($a['user']['id'] == $this->config->item('telegram_bot_id')){
					unset($admins[$k]);
					array_push($admins, $a);
				}
			}
			foreach($admins as $k => $a){
				if($a['user']['id'] == $this->config->item('telegram_bot_id')){
					$str .= "Y yo, el Profesor Oak :)";
					continue;
				}
				$pk = $pokemon->user($a['user']['id']);
				if(!empty($pk)){ $str .= $telegram->emoji(":heart-" .$teams[$pk->team] .":") ." L" .$pk->lvl ." @" .$pk->username ." - "; }
				$str .= $a['user']['first_name'] ." ";
				if(isset($a['user']['username']) && ($a['user']['username'] != $pk->username) ){ $str .= "( @" .$a['user']['username'] ." )"; }
				if($k == 0){ $str .= "\n"; } // - Creator
				$str .= "\n";
			}

			// Reply to private?
			// ->chat( $telegram->user->id )
			$this->analytics->event('Telegram', 'Admin List');
			$telegram->send
				->notification(FALSE)
				->text($str)
			->send();
		}

		// Preguntar si el usuario es administrador
		elseif($telegram->text_has(["soy", "es", "eres"], ["admin", "administrador"], TRUE) && $telegram->words() <= 5 && $telegram->is_chat_group()){
			$admin = NULL;
			if($telegram->text_has("soy")){ $admin = $telegram->user->id; }
			elseif($telegram->text_has(["es", "eres"]) && $telegram->has_reply){ $admin = $telegram->reply_user->id; }
			else{ exit(); }

			$admins = $this->admins(FALSE);
			$text = "Nop.";
			if(in_array($admin, $admins)){
				$text = "Sip, es admin.";
			}
			$this->analytics->event('Telegram', 'Ask for admin');
			$telegram->send
				->notification(FALSE)
				// ->reply_to($telegram->reply_user->id)
				->text($text)
			->send();
			exit();
		}

		// configurar el bot (solo creador/admin/chat privado)
		elseif(
			$telegram->text_has("/set", TRUE) &&
			$telegram->words() == 3 &&
			(
				( $telegram->is_chat_group() && $this->admins(TRUE) ) or
				( !$telegram->is_chat_group() )
			)
		){
			$key = $telegram->words(1);
			$value = $telegram->words(2);

			$this->analytics->event('Telegram', 'Set config', $key);
			$set = $pokemon->settings($telegram->chat->id, $key, $value);
			$announce = $pokemon->settings($telegram->chat->id, 'announce_settings');
			$telegram->send
				->chat( $this->config->item('creator') )
				->text("CONFIG: $key " .json_encode($set) ." -> " .json_encode($value))
			->send();

			if( ($set !== FALSE or $set > 0) && ($announce == TRUE) ){
				$telegram->send
					->notification(FALSE)
					->reply_to(TRUE)
					->text("Configuración establecida: *$value*", TRUE)
				->send();
			}
			exit();
		}
		// establecer flag del usuario
		elseif(
			$telegram->text_has("/setflag", TRUE) &&
			(in_array($telegram->words(), [2,3])) &&
			$telegram->user->id == $this->config->item('creator')
		){
			if($telegram->words() == 2 and $telegram->has_reply){
				$f_user = $telegram->reply_user->id;
			}elseif($telegram->words() == 3){
				$search = $telegram->words(1); // Penúltima
				if($telegram->text_mention()){
					$search = $telegram->text_mention();
					if(is_array($search)){ $search = key($search); }
					$serach = str_replace("@", "", $search);
				}
				$f_user = $pokemon->user($search);
				if(empty($f_user)){ exit(); }
				$f_user = $f_user->telegramid;
			}
			$flag = $telegram->last_word();
			$pokemon->user_flags($f_user, $flag, TRUE);
		}

		elseif(
			$telegram->text_has("/get", TRUE) &&
			$telegram->words() == 2
		){
			$get = $telegram->chat->id;
			if($telegram->is_chat_group()){
				$admins = $this->admins(TRUE);
				if(!in_array($user->id, $admins)){ return; }
				if($telegram->has_reply && $telegram->user->id == $this->config->item('creator')){ $get = $telegram->reply_user->id; }
			}

			$word = $telegram->last_word();
			$chat = $telegram->chat->id;
			if(strpos($word, "+private") !== FALSE){
				$chat = $telegram->user->id;
				$word = trim(str_replace("+private", "", $word));
			}
			if(strtolower($word) == "all"){ $word = "*" ; } // ['say_hello', 'say_hey', 'play_games', 'announce_welcome', 'announce_settings', 'shutup']; }
			$value = $pokemon->settings($get, $word);
			$text = "";
			if(is_array($value)){
				foreach($value as $k => $v){
					$text .= "$k: $v\n";
				}
			}else{
				$text = "*" .json_encode($value) ."*";
			}
			$telegram->send
				->chat($chat)
				->notification( ($chat != $telegram->chat->id) )
				// ->reply_to( ($chat == $telegram->chat->id) )
				->text($text, (!is_array($value)))
			->send();
			exit();

		}elseif($telegram->text_has("/mode", TRUE) && $telegram->user->id == $this->config->item('creator')){
			$user = ($telegram->has_reply ? $telegram->reply_user->id : $telegram->user->id);
			if($telegram->words() == 1){
				$step = $pokemon->step($user);
				if(empty($step)){ $step = NULL; }
				$telegram->send->text("*" .json_encode($step) ."*", TRUE)->send();
			}elseif($telegram->words() == 2){
				$step = $pokemon->step($user, $telegram->last_word());
				$telegram->send->text("set!")->send();
			}
			exit();
		}elseif(
			($telegram->text_has(["oak", "profe"], "limpia")) or $telegram->text_has("/clean", TRUE)
			or ($telegram->text_contains("a fregar"))
		){
			$admins = $this->admins(TRUE);

			if(in_array($user->id, $admins)){
				$this->analytics->event('Telegram', 'Clean');
				$telegram->send
					->notification(FALSE)
					->text(".\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n.")
				->send();
			}
		}
		// echar al bot del grupo
		elseif($telegram->text_has(["oak", "profe"], ["sal", "vete"], TRUE) && $telegram->is_chat_group() && $telegram->words() < 4){
			$admins = $this->admins(TRUE);

			if(in_array($user->id, $admins)){
				$this->analytics->event('Telegram', 'Leave group');
				$telegram->send
					->notification(FALSE)
					->text("Jo, pensaba que me queríais... :(\nBueno, si me necesitáis, ya sabéis donde estoy.")
				->send();

				$pokemon->group_disable($telegram->chat->id);
				$telegram->send->leave_chat();
			}
		}
		// marcar otro usuario (solo creador)
		elseif(
			$telegram->text_has(["Éste", "este"], TRUE) &&
			$telegram->has_reply &&
			$user->id == $this->config->item('creator')
		){
			$reply = $telegram->reply_user;
			$word = $telegram->last_word();

			// marcar de un color
			if(in_array(strtolower($word), ["rojo", "azul", "amarillo"])){
				if( $pokemon->register( $reply->id, $word ) !== FALSE){
					$name = trim("$reply->first_name $reply->last_name");
					$telegram->send
						->notification(FALSE)
						->text("Vale jefe, marco a $name como *$word*!", TRUE)
					->send();
					$pokemon->update_user_data($reply->id, 'fullname', $name);
				}elseif($pokemon->user_exists( $reply->id )){
					$telegram->send
						->notification(FALSE)
						->text("Con que un topo, eh? ¬¬ Bueno, ahora es *$word*.\n_Cuidadín, que te estaré vigilando..._", TRUE)
					->send();
					$pokemon->update_user_data($reply->id, 'team', $pokemon->team_text($word));
				}
			}

			// guardar nombre del user
			elseif($telegram->text_has("se llama")){
				if($pokemon->user_exists($word)){
					$telegram->send
						->notification(FALSE)
						->reply_to(TRUE)
						->text("Oye jefe, que ya hay alguien que se llama así :(")
					->send();
				}else{
					$pokemon->update_user_data($reply->id, 'username', $word);
					$this->analytics->event('Telegram', 'Register username');
					$telegram->send
						->notification(FALSE)
						->text("De acuerdo, *@$word*!", TRUE)
					->send();
				}
			}

			// guardar nivel del user
			elseif($telegram->text_has("es nivel")){
				if(is_numeric($word) && $word >= 5 && $word <= 40){
					$this->analytics->event('Telegram', 'Change level', $word);
					$pokemon->update_user_data($reply->id, 'lvl', $word);
				}
			}

			exit();
		}elseif($telegram->text_has("Te valido", TRUE) && $telegram->words() <= 3){
			if(!$pokeuser->authorized){ exit(); }
			$target = NULL;
			if($telegram->words() == 2 && $telegram->has_reply){
				$target = $telegram->reply_user->id;
				if($telegram->reply_is_forward && $telegram->reply_user->id != $telegram->reply->forward_from->id){
					$target = $telegram->reply->forward_from['id'];
				}
			}elseif($telegram->words() == 3){
				$target = $telegram->last_word(TRUE);
				if($target[0] == "@"){ $target = substr($target, 1); }
				$target = $pokemon->find_users($target);
				if($target == FALSE or count($target) > 1){ exit(); }
				$target = $target[0]['telegramid'];
			}

			if($pokemon->user_verified($target)){ exit(); } // Ya es válido.
			if($pokemon->verify_user($telegram->user->id, $target)){
				$telegram->send
					->notification(FALSE)
					->text( $telegram->emoji(":green-check:") )
				->send();
			}
		}elseif($telegram->text_has("/investigate", TRUE) && $telegram->is_chat_group()){
			$admins = $this->admins(TRUE);

			if(!in_array($telegram->user->id, $admins)){ die(); }

			$team = $pokemon->settings($telegram->chat->id, 'team_exclusive');
			if($team !== NULL){

				$run = $pokemon->settings($telegram->chat->id, 'investigation');
				if($run !== NULL){
					if(time() <= ($run + 3600)){ exit(); }
				}
				$run = $pokemon->settings($telegram->chat->id, 'investigation', time());

				$this->analytics->event('Telegram', 'Investigation', $team);
				$teams = ["Y", "B", "R"];
				unset( $teams[ array_search($team, $teams) ] );
				$users = $pokemon->get_users($teams);
				$c = 0;
				$dot = 0;
				$topos = array();

				$updates = $telegram->send
					->notification(FALSE)
					->text("*Progreso:* ", TRUE)
				->send();
				foreach($users as $u){
					if(($c % 100 == 0) or ($c % 100 == 50) or ($c >= count($users))){
						$msg = "*Progreso:* " .floor(($c / count($users) * 100)) ."%";
						if($dot++ > 3){ $dot = 0; }
						for($i = 0; $i < $dot; $i++){ $msg .= "."; }
						$msg .= " ($c)";

						$run = $pokemon->settings($telegram->chat->id, 'investigation');
						if($run === NULL){ $msg = "Cancelado. $c comprobados."; }

						$telegram->send
							->message($updates['message_id'])
							->text($msg, TRUE)
						->edit('text');

						if($run === NULL){ die(); }
					}
					$c++;

					$q = $telegram->send
						->chat($telegram->chat->id)
					->get_member_info($u);

					if($q == FALSE or $q['status'] == "left"){ continue; }
					else{
						$topos[] = $q;
						$telegram->send
							->notification(TRUE)
							->text("*TOPO!* " .$q['user']['first_name'] .(isset($q['user']['username']) ? " @" .$q['user']['username'] : "" ), TRUE)
						->send();
					}
				}

				$str = "*Lista final:*\n";
				foreach($topos as $t){
					$str .= $t['user']['first_name'] .(isset($t['user']['username']) ? " @" .$t['user']['username'] : "" ) ."\n";
				}

				$telegram->send
					->notification(FALSE)
					->text($str . "\nFinalizado.", TRUE)
				->send();
			}else{
				$telegram->send
					->notification(FALSE)
					->text("No es un grupo cerrado.")
				->send();
			}
			exit();
		}

		// contar miembros de cada color
		elseif($telegram->text_has("/count", TRUE) && $telegram->is_chat_group()){
			// $admins = $telegram->get_admins();
			$admins[] = $this->config->item('creator');

			if(!in_array($telegram->user->id, $admins)){ exit(); }

			$run = $pokemon->settings($telegram->chat->id, 'investigation');
			if($run !== NULL){
				if(time() <= ($run + 3600)){ exit(); }
			}
			$run = $pokemon->settings($telegram->chat->id, 'investigation', time());

			$teams = ["Y", "B", "R"];
			// unset( $teams[ array_search($team, $teams) ] );
			$users = $pokemon->get_users($teams);
			$c = 0;
			$dot = 0;
			$pks = array();
			$current_chat = $telegram->send->get_members_count();

			$updates = $telegram->send
				->notification(FALSE)
				->text("*Progreso:* ", TRUE)
			->send();
			foreach($users as $u){
				if(($c % 100 == 0) or ($c % 100 == 50) or ($c >= count($users))){
					$msg = "*Progreso:* " .floor(($c / count($users) * 100)) ."%";
					$msg .= " (" .count($pks["Y"]) ." / " .count($pks["R"]) ." / " .count($pks["B"]) .") ";
					$msg .= "de " .$current_chat;
					if($dot++ >= 3){ $dot = 0; }
					for($i = 0; $i < $dot; $i++){ $msg .= "."; }
					$msg .= " ($c)";

					$run = $pokemon->settings($telegram->chat->id, 'investigation');
					if($run === NULL){ $msg = "Cancelado. $c comprobados."; }

					$telegram->send
						->message($updates['message_id'])
						->text($msg, TRUE)
					->edit('text');

					if($run === NULL){ die(); }
				}
				$c++;

				$q = $telegram->send
					->chat($telegram->chat->id)
				->get_member_info($u);

				if($q == FALSE or $q['status'] == "left"){ continue; }
				else{
					$pk = $pokemon->user($u);
					if(!empty($pk)){
						$pks[$pk->team][] = $u;
					}
				}
			}

			$str = "Lista final:\n";
			$str .= ":heart-yellow: " .count($pks["Y"]) ."\n";
			$str .= ":heart-red: " .count($pks["R"]) ."\n";
			$str .= ":heart-blue: " .count($pks["B"]) ."\n";
			$str .= "Faltan: " .($current_chat - count($pks["Y"]) - count($pks["R"]) - count($pks["B"]));
			$str = $telegram->emoji($str);

			$telegram->send
				->notification(FALSE)
				->text($str)
			->send();

		}elseif($telegram->text_contains("mal") && $telegram->words() < 4 && $telegram->has_reply){
			$telegram->send
				->chat($telegram->chat->id)
				->notification(FALSE)
				->message($telegram->reply->message_id)
				->text("Perdon :(")
			->edit('message');
		}elseif($telegram->text_has(["/stats"], TRUE)){
			$stats = $pokemon->count_teams();
			$text = "";
			$equipos = ["Y" => "yellow", "B" => "blue", "R" => "red"];
			foreach($stats as $s => $v){
				$text .= $telegram->emoji(":heart-" .$equipos[$s] .":") ." $v\n";
			}
			$text .= "*TOTAL:* " .array_sum($stats);
			$telegram->send
				->notification(FALSE)
				// ->reply_to(TRUE)
				->text($text, TRUE)
			->send();
			exit();
		}elseif($telegram->text_has(["grupo offtopic", "/offtopic"]) && $telegram->is_chat_group()){
			$offtopic = $pokemon->settings($telegram->chat->id, 'offtopic_chat');
			$chatgroup = NULL;
			if(!empty($offtopic)){
				if($offtopic[0] != "@" and strlen($offtopic) == 22){
					$chatgroup = "https://telegram.me/joinchat/" .$offtopic;
				}else{
					$chatgroup = $offtopic;
				}
			}
			if(!empty($chatgroup)){
				$this->analytics->event('Telegram', 'Offtopic Link');
				$telegram->send
					->notification(FALSE)
					->text("Offtopic: $chatgroup")
				->send();
			}
			exit();
		}elseif(
			(
				$telegram->text_has(["reglas", "normas"], "del grupo") or
				$telegram->text_has(['dime', 'ver'], ["las reglas", "las normas", "reglas", "normas"], TRUE) or
				$telegram->text_has(["/rules", "/normas"], TRUE)
			) and
			!$telegram->text_has(["poner", "actualizar", "redactar", "escribir", "cambiar"]) and
			$telegram->is_chat_group()
		){
			$this->analytics->event('Telegram', 'Rules', 'display');
			$rules = $pokemon->settings($telegram->chat->id, 'rules');

			$text = "No hay reglas escritas.";
			if(!empty($rules)){ $text = json_decode($rules); }
			$chat = $chat->id;
			if(strlen($rules) > 500){
				$chat = $user->id;
				$telegram->send
					->notification(FALSE)
					->reply_to(TRUE)
					->text("Te las envío por privado, " .$user->first_name .".")
				->send();
			}

			$telegram->send
				->notification(FALSE)
				->chat($chat)
				// ->disable_web_page_preview()
				->text($text)
			->send();
			exit();
		}elseif(
			( $telegram->text_has(["link", "enlace"], ["del grupo", "de este grupo", "grupo"]) or
			$telegram->text_has(["/linkgroup", "/grouplink"], TRUE))
		){
			$link = $pokemon->settings($telegram->chat->id, 'link_chat');

			$word = $telegram->last_word(TRUE);
			if(!is_numeric($word) and strlen($word) >= 4 and !$telegram->text_has("este")){ // XXX comprobar que no dé problemas
				$s = $pokemon->group_link($word);
				if(!empty($s)){ $link = $s; }
			}
			$chatgroup = NULL;
			if(!empty($link)){
				if($link[0] != "@" and strlen($link) == 22){
					$chatgroup = "https://telegram.me/joinchat/" .$link;
				}else{
					$chatgroup = $link;
				}
			}
			if(!empty($chatgroup)){
				$this->analytics->event('Telegram', 'Group Link');
				$telegram->send
					->notification(FALSE)
					->disable_web_page_preview()
					->text("Link: $chatgroup")
				->send();
			}
			exit();
		}

		// ---------------------
		// Apartado de cuenta
		// ---------------------

		// guardar nombre de user
		if($telegram->text_has(["Me llamo", "Mi nombre es", "Mi usuario es"], TRUE) && $telegram->words() <= 4 && $telegram->words() > 2){
			if(!empty($pokeuser->username)){ exit(); }
			$word = $telegram->last_word(TRUE);
			$this->_set_name($user->id, $word, FALSE);
			exit();
		}
		// guardar nivel del user
		elseif(
			$telegram->text_has("Soy", ["lvl", "nivel", "L", "level"]) or
			$telegram->text_has("Soy L", TRUE) or // HACK L junta
			$telegram->text_has("Acabo de subir al")
		){
			$level = filter_var($telegram->text(), FILTER_SANITIZE_NUMBER_INT);
			if(is_numeric($level)){
				$command = $pokemon->settings($user->id, 'last_command');
				if($command == "WHOIS" && $telegram->is_chat_group()){
					/* $telegram->send
						->notification(FALSE)
						->text("Vale, pero por favor, deja de hacer SPAM preguntándome todo el rato.")
						// Vale. Como me vuelvas a preguntar quien eres, te mando a la mierda. Que lo sepas.
					->send(); */
				}elseif($level == $pokeuser->lvl or $command == "LEVELUP"){
					$telegram->send
						->notification(FALSE)
						->text("Que ya lo sé, pesado...")
					->send();
				}
				if($level >= 5 && $level <= 35){
					$pokemon->update_user_data($telegram->user->id, 'lvl', $level);
				}
				$this->analytics->event('Telegram', 'Change level', $level);
				$pokemon->settings($user->id, 'last_command', 'LEVELUP');
			}
			exit();
		}

		// pedir info sobre uno mismo
		elseif(
			$telegram->text_has(["Quién soy", "Cómo me llamo", "who am i"], TRUE) or
			($telegram->text_has(["profe", "oak"]) && $telegram->text_has("Quién soy") && $telegram->words() <= 5)
		){
			$str = "";
			$team = ['Y' => "Amarillo", "B" => "Azul", "R" => "Rojo"];

			$this->analytics->event('Telegram', 'Whois', 'Me');
			if(empty($pokeuser->username)){ $str .= "No sé como te llamas, sólo sé que "; }
			else{ $str .= '$pokemon, '; }

			$str .= 'eres *$team* $nivel. $valido';

			// si el bot no conoce el nick del usuario
			if(empty($pokeuser->username)){ $str .= "\nPor cierto, ¿cómo te llamas *en el juego*? \n_Me llamo..._"; }

			$chat = ($telegram->is_chat_group() && $this->is_shutup() && !in_array($telegram->user->id, $this->admins(TRUE)) ? $telegram->user->id : $telegram->chat->id);

			$repl = [
				'$nombre' => $new->first_name,
				'$apellidos' => $new->last_name,
				'$equipo' => $team[$pokeuser->team],
				'$team' => $team[$pokeuser->team],
				'$usuario' => "@" .$new->username,
				'$pokemon' => "@" .$pokeuser->username,
				'$nivel' => "L" .$pokeuser->lvl,
				'$valido' => ($pokeuser->verified ? ':green-check:' : ':warning:')
			];

			$str = str_replace(array_keys($repl), array_values($repl), $str);
			$pokemon->settings($user->id, 'last_command', 'WHOIS');

			$telegram->send
				->chat($chat)
				->reply_to( ($chat == $telegram->chat->id) )
				->notification(FALSE)
				->text($telegram->emoji($str), TRUE)
			->send();
		}
		// Si pregunta por Ash...
		elseif($telegram->text_has("quién es Ash") && $telegram->words() <= 7){
			$this->analytics->event('Telegram', 'Jokes', 'Ash');
			$telegram->send->text("Ah! Ese es un *cheater*, es nivel 100...\nLo que no sé de dónde saca tanto dinero para viajar tanto...", TRUE)->send();
			exit();
		}
		// si pregunta por un usuario
		elseif(
			$telegram->text_has("quién", ["es", "eres"]) &&
			!$telegram->text_contains(["programa", "esta"]) &&
			$telegram->words() <= 5
		){
			$str = "";
			$teams = ['Y' => "Amarillo", "B" => "Azul", "R" => "Rojo"];
			// pregunta usando respuesta
			if($telegram->has_reply){
				$this->analytics->event('Telegram', 'Whois', 'Reply');
				// si el usuario por el que se pregunta es el bot
				if($telegram->reply_user->id == $this->config->item("telegram_bot_id")){
					$str = "Pues ese soy yo mismo :)";
				// HACK Un bot no detecta reply de otro bot.
				// }elseif(strtolower(substr($telegram->reply_user->username, -3)) == "bot"){
				//	$str = "Es un bot.";
				}else{
					$user_search = $telegram->reply_user->id;
					if($telegram->reply_is_forward && $telegram->reply_user->id != $telegram->reply->forward_from->id){
						$user_search = $telegram->reply->forward_from['id']; // FIXME -> to object?
					}

					// si el usuario es desconocido
					$info = $pokemon->user( $user_search );
					if(empty($info)){
						$str = "No sé quien es.";
					}else{
						// si no se conoce el nick pero si el equipo
						if(empty($info->username)){ $str .= "No sé como se llama, sólo sé que "; }
						// si se conoce el equipo
						else{ $str .= "@$info->username, "; }

						$str .= 'es *$team* $nivel.' ."\n";

						$flags = $pokemon->user_flags($info->telegramid);

						// añadir emoticonos basado en los flags del usuario
						if($info->verified){ $str .= $telegram->emoji(":green-check: "); }
						else{ $str .= $telegram->emoji(":warning: "); }
						// ----------------------
						if($info->blocked){ $str .= $telegram->emoji(":forbid: "); }
						if($info->authorized){ $str .= $telegram->emoji(":star: "); }
						if(in_array("ratkid", $flags)){ $str .= $telegram->emoji(":mouse: "); }
						if(in_array("multiaccount", $flags)){ $str .= $telegram->emoji(":multiuser: "); }
						if(in_array("gps", $flags)){ $str .= $telegram->emoji(":satellite: "); }
						if(in_array("bot", $flags)){ $str .= $telegram->emoji(":robot: "); }
						if(in_array("rager", $flags)){ $str .= $telegram->emoji(":fire: "); }
						if(in_array("troll", $flags)){ $str .= $telegram->emoji(":joker: "); }
						if(in_array("spam", $flags)){ $str .= $telegram->emoji(":spam: "); }
						if(in_array("hacks", $flags)){ $str .= $telegram->emoji(":laptop: "); }
					}
				}
			}
			// pregunta usando nombre
			elseif(
				// ( ($telegram->words() == 3) or ($telegram->words() == 4 && $telegram->last_word() == "?") ) and
				( $telegram->text_has("quién es") )
			){
				$this->analytics->event('Telegram', 'Whois', 'User');
				if($telegram->text_mention()){ $text = $telegram->text_mention(); if(is_array($text)){ $text = key($text); } } // CHANGED Siempre coger la primera mención
				elseif($telegram->words() == 4){ $text = $telegram->words(2); } // 2+1 = 3 palabra
				else{ $text = $telegram->last_word(); } // Si no hay mención, coger la última palabra
				$text = $telegram->clean('alphanumeric', $text);
				if(strlen($text) < 4){ exit(); }
				$pk = $this->parse_pokemon();
				if(!empty($pk['pokemon'])){ $this->_pokedex($pk['pokemon']); exit(); }
				$info = $pokemon->user($text);

				// si es un bot
				if(strtolower(substr($text, -3)) == "bot"){
					$str = "Es un bot."; // Yo no me hablo con los de mi especie.\nSi, queda muy raro, pero nos hicieron así...";
				// si no se conoce
				}elseif(empty($info)){
					$str = "No sé quien es $text.";
				}else{
					$str = 'Es *$team* $nivel. $valido';
				}
			}

			if(!empty($str)){
			$chat = ($telegram->is_chat_group() && $this->is_shutup() && !in_array($telegram->user->id, $this->admins(TRUE)) ? $telegram->user->id : $telegram->chat->id);

			$repl = [
				// '$nombre' => $new->first_name,
				// '$apellidos' => $new->last_name,
				'$equipo' => $teams[$info->team],
				'$team' => $teams[$info->team],
				// '$usuario' => "@" .$new->username,
				'$pokemon' => "@" .$info->username,
				'$nivel' => "L" .$info->lvl,
				'$valido' => ($info->verified ? ':green-check:' : ':warning:')
			];

			$str = str_replace(array_keys($repl), array_values($repl), $str);
			$this->last_command('WHOIS');
			// $pokemon->settings($user->id, 'last_command', 'WHOIS');

				$telegram->send
					->chat($chat)
					->reply_to( (($chat == $telegram->chat->id && $telegram->has_reply) ? $telegram->reply->message_id : NULL) )
					->notification(FALSE)
					->text($telegram->emoji($str), TRUE)
				->send();
			}
			exit();

		// Responder el nivel de un entrenador.
		}elseif($telegram->text_has("que") && $telegram->text_has(["lvl", "level", "nivel"], ["eres", "es", "soy"]) && $telegram->words() <= 7){
			$user = $telegram->user->id;
			if($telegram->text_has(["eres", "es"])){
				if(!$telegram->has_reply){ exit(); }
				$user = $telegram->reply_user->id;
			}

			$u = $pokemon->user($user);
			$text = NULL;
			if(!empty($u) && $u->lvl >= 5){
				$this->analytics->event('Telegram', 'Whois', 'Level');
				$text = ($telegram->text_has(["eres", "es"]) ? "Es" : "Eres") ." L" .$u->lvl .".";
			}else{
				$text = ($telegram->text_has("soy") ? "No lo sé. ¿Y si me lo dices?" : "No lo sé. :(");
			}
			$telegram->send
				->notification(FALSE)
				// ->reply_to(TRUE)
				->text($text)
			->send();
			exit();
		}elseif($telegram->text_has("estoy aquí")){
			// Quien en cac? Que estoy aquí

		// ---------------------
		// Información General Pokemon
		// ---------------------

		}
		// pregunta sobre el creador de Oak
		elseif(
			!$telegram->text_has(["qué", "cómo"]) &&
			$telegram->text_has(["quién", "oak", "profe"]) &&
			$telegram->text_has(["es", "te", "tu", "hizo a", "le"]) &&
			$telegram->text_has(["programado", "hecho", "hizo", "creado", "creador"]) &&
			$telegram->words() <= 8
		){
			$telegram->send->notification(FALSE)->text("Pues mi creador es @duhow :)")->send();
			exit();
		}
		// pregunta sobre Eevee
		elseif($telegram->text_has("llama") && $telegram->text_has("eevee")){
			$pkmn = "";
			if($telegram->text_has("agua")){
				$pkmn = "Vaporeon";
			}elseif($telegram->text_has("fuego")){
				$pkmn = "Flareon";
			}elseif($telegram->text_has(["eléctrico", "electricidad"])){
				$pkmn = "Jolteon";
			}
			if(!empty($pkmn)){ $telegram->send->notification(FALSE)->text("Creo que te refieres a *$pkmn*?", TRUE)->send(); }
			exit();
		}
		// Estado Pokemon Go
		elseif(
			$telegram->text_has(["funciona", "funcionan", "va", "caído", "caídos", "caer", "muerto", "estado"]) &&
		 	$telegram->text_has(["juego", "pokémon", "servidor", "servidores", "server", "servers"]) &&
			!$telegram->text_contains(["ese", "a mi me va", "a mis", "Que alg", "esa", "este", "caza", "su bola", "atacar", "cambi", "futuro", "esto", "para", "mapa", "contando", "va lo de", "llevamos", "a la", "va bastante bien"]) &&
			$telegram->words() < 15 && $telegram->words() > 2
		){
			/* $web = file_get_contents("http://www.mmoserverstatus.com/pokemon_go");
			$web = substr($web, strpos($web, "Spain"), 45);
			if(strpos($web, "red") !== FALSE){
				$str = "*NO funciona, hay problemas en España.*\n(Si bueno, aparte de los políticos.)";
			}elseif(strpos($web, "green") !== FALSE){
				$str = "Pokemon GO funciona correctamente! :)";
			} */

			// Conseguir estado mediante API JSON
			$web = file_get_contents("https://go.jooas.com/status");
			$web = json_decode($web);

			$pkgo = ($web->go_online == TRUE ? ':green-check:' : ':times:');
			$ptc = ($web->ptc_online == TRUE ? ':green-check:' : ':times:');

			$pkgo_t = $web->go_idle;
			$pkgo = ($pkgo == ":green-check:" && $pkgo_t <= 45 ? ':warning:' : ':green-check:');
			$pkgo_t = ($pkgo_t > 120 ? floor($pkgo_t / 60) ."h" : $pkgo_t ."m" );

			$ptc_t = $web->ptc_idle;
			$ptc = ($ptc == ":green-check:" && $ptc_t <= 45 ? ':warning:' : ':green-check:');
			$ptc_t = ($ptc_t > 120 ? floor($ptc_t / 60) ."h" : $ptc_t ."m" );
			// Todo funciona bien
			if($pkgo == TRUE && $ptc == TRUE){ $str = "¡Todo está funcionando correctamente!"; }
			// Problemas con PTC
			elseif($ptc != TRUE){ $str = "El juego funciona, pero parece que el *Club de Entrenadores tiene problemas.*\n_(¿Y cuándo no los tiene?)_"; }
			// Esto no va ni a la de tres
			else{ $str = "Parece que *hay problemas con el juego.*"; }

			$str .= "\n\n$pkgo PKMN ($pkgo_t)\n" ."$ptc PTC ($ptc_t)\n";
			// $str .= "_powered by https://go.jooas.com/ _";
			$str = $telegram->emoji($str);

			$telegram->send
				->notification(TRUE)
				// ->reply_to(TRUE)
				->text($str, TRUE)
			->send();
			exit();

		}elseif($telegram->text_has("Lista de", ["enlaces", "links"], TRUE)){
			$str = "";
			$links = $pokemon->link("ALL");
			$str = implode("\n- ", array_column($links, 'name'));
			$telegram->send
				->notification(FALSE)
				->text("- " .$str)
			->send();
			exit();
		}elseif(
			$telegram->text_has(["Enlace", "Link"], TRUE) or
			$telegram->text_has(["/enlace", "/link"], TRUE) and
			!$telegram->text_contains("http") // and
			// $telegram->words() < 6
		){
			$text = $telegram->text();
			$text = explode(" ", $text);
			unset($text[0]);
			$command = trim(strtolower($telegram->last_word(TRUE)));

			if(in_array($command, ["aquí", "aqui"])){
				$chat = $telegram->chat->id;
				unset( $text[end(array_keys($text))] );
			}
			else{ $chat = $telegram->user->id; }

			$text = implode(" ", $text);
			$text = trim(strtolower($text));

			$link = $pokemon->link($text);
			if(!empty($link) && count($link) == 1){
				$telegram->send
					->chat($chat)
					->text($link)
				->send();
			}elseif(is_numeric($link) or count($link) > 1){
				$telegram->send
					->chat($chat)
					->text("Demasiadas coincidencias. Vuelve a probar.")
				->send();
			}

			exit();
		}

		// Ver los IV o demás viendo stats Pokemon.
		elseif($telegram->text_has(["tengo", "calculame", "calcula iv", "calcular iv", "he conseguido", "he capturado"], TRUE) && $telegram->words() >= 4){
			$pk = $this->parse_pokemon();
			// TODO contar si faltan polvos o si se han especificado "caramelos" en lugar de polvos, etc.
			if(!empty($pk['pokemon'])){
				if(isset($pk['stardust'])){
					// TODO el Pokemon sólo puede ser +1.5 del nivel de entrenador (guardado en la cuenta)
					// Calcular posibles niveles
					$levels = $pokemon->stardust($pk['stardust'], $pk['powered']);
					// $telegram->send->text(json_encode($levels))->send();
					// Si tiene HP y CP puesto, calvular IV
					if(isset($pk['hp']) and isset($pk['cp'])){
						// $chat = ($pokemon->settings($chat->id, 'shutup') && $user->id != $this->config->item('creator') ? $telegram->user->id : $telegram->chat->id);
						$chat = ($telegram->is_chat_group() && $this->is_shutup() && !in_array($telegram->user->id, $this->admins(TRUE)) ? $telegram->user->id : $telegram->chat->id);
						$pokedex = $pokemon->pokedex($pk['pokemon']);
						$this->analytics->event("Telegram", "Calculate IV", $pokedex->name);
						// De los niveles que tiene...
						$table = array();
						$low = 100;
						$high = 0; // HACK invertidas
						foreach($levels as $lvl){
							$lvlmp = $pokemon->level($lvl)->multiplier;
							$pow = pow($lvlmp, 2) * 0.1;
							for($IV_STA = 0; $IV_STA < 16; $IV_STA++){
								$hp = max(floor(($pokedex->stamina + $IV_STA) * $lvlmp), 10);
								// Si tenemos el IV de HP y coincide con su vida...
								if($hp == $pk['hp']){
									$lvl_STA = sqrt($pokedex->stamina + $IV_STA) * $pow;
									$cps = array(); // DEBUG
									for($IV_DEF = 0; $IV_DEF < 16; $IV_DEF++){
			                            for($IV_ATK = 0; $IV_ATK < 16; $IV_ATK++){
											$cp = floor( ($pokedex->attack + $IV_ATK) * sqrt($pokedex->defense + $IV_DEF) * $lvl_STA);
											// Si el CP calculado coincide con el nuestro, agregar posibilidad.
											if($cp == $pk['cp']){
												$sum = (($IV_ATK + $IV_DEF + $IV_STA) / 45) * 100;
												if($sum > $high){ $high = $sum; }
												if($sum < $low){ $low = $sum; }
												$table[] = ['level' => $lvl, 'atk' => $IV_ATK, 'def' => $IV_DEF, 'sta' => $IV_STA];
											}
											$cps[] = $cp;
										}
									}
									if($user->id == $this->config->item('creator')){
										// $telegram->send->text(json_encode($cps))->send(); // DEBUG
									}
								}
							}
						}
						if(count($table) > 1 and ($pk['attack'] or $pk['defense'] or $pk['stamina'])){
							// si tiene ATK, DEF O STA, los resultados
							// que lo superen, quedan descartados.
							foreach($table as $i => $r){
								// if($pk['attack'] and ( ($r['atk'] < $r['def']) or ($r['atk'] < $r['sta']) )){ unset($table[$i]); }
								// if($pk['defense'] and ( ($r['def'] < $r['atk']) or ($r['def'] < $r['atk']) )){ unset($table[$i]); }
								// if($pk['stamina'] and ( ($r['sta'] < $r['atk']) or ($r['sta'] < $r['def']) )){ unset($table[$i]); }
								if($pk['attack'] and ( max($r['atk'], $r['def'], $r['sta']) != $r['atk'] )){ unset($table[$i]); continue; }
								if($pk['defense'] and ( max($r['atk'], $r['def'], $r['sta']) != $r['def'] )){ unset($table[$i]); continue; }
								if($pk['stamina'] and ( max($r['atk'], $r['def'], $r['sta']) != $r['sta'] )){ unset($table[$i]); continue; }
								if($pk['attack'] and isset($pk['ivcalc']) and !in_array($r['atk'], $pk['ivcalc'])){ unset($table[$i]); continue; }
								if($pk['defense'] and isset($pk['ivcalc']) and !in_array($r['def'], $pk['ivcalc'])){ unset($table[$i]); continue; }
								if($pk['stamina'] and isset($pk['ivcalc']) and !in_array($r['sta'], $pk['ivcalc'])){ unset($table[$i]); continue; }
								if((!$pk['attack'] or !$pk['defense'] or !$pk['stamina']) and ($r['atk'] + $r['def'] + $r['sta'] == 45)){ unset($table[$i]); continue; }
							}
							$low = 100;
							$high = 0;
							foreach($table as $r){
								$sum = (($r['atk'] + $r['def'] + $r['sta']) / 45) * 100;
								if($sum > $high){ $high = $sum; }
								if($sum < $low){ $low = $sum; }
							}
						}

						$frases = [
							'Es una.... mierda. Si quieres caramelos, ya sabes que hacer.',
							'Bueno, no está mal. :)',
							'Oye, ¡pues mola!',
							'Menuda suerte que tienes, cabrón...'
						];

						if(count($table) == 0){
							$text = "Los cálculos no me salen...\n¿Seguro que me has dicho bien los datos?";
						}elseif(count($table) == 1){
							if($low == $high){ $sum = round($high, 1); }
							reset($table); // HACK Reiniciar posicion
							$r = current($table); // HACK Seleccionar primer resultado
							$frase = 0;
							if($sum <= 50){ $frase = 0; }
							elseif($sum > 50 && $sum <= 66){ $frase = 1; }
							elseif($sum > 66 && $sum <= 80){ $frase = 2; }
							elseif($sum > 80){ $frase = 3; }
							$text = "Pues parece que tienes un *$sum%*!\n"
									.$frases[$frase] ."\n"
									."*L" .round($r['level']) ."* " .$r['atk'] ." ATK, " .$r['def'] ." DEF, " .$r['sta'] ." STA";
						}else{
							$low = round($low, 1);
							$high = round($high, 1);
							$text = "He encontrado *" .count($table) ."* posibilidades, "; // \n
							if($low == $high){ $text .= "con un *$high%*."; }
							else{ $text .= "entre *" .round($low, 1) ."% - " .round($high, 1) ."%*."; }

							if($high <= 50 or ($low <= 60 and $high <= 60) ){ $frase = 0; }
							elseif($low > 75){ $frase = 3; }
							elseif($low > 66){ $frase = 2; }
							elseif($low > 50 or ($high >= 75 and $low <= 65)){ $frase = 1; }

							$text .= "\n" .$frases[$frase] ."\n";

							// Si hay menos de 6 resultados, mostrar.
							if(count($table) <= 6){
								$text .= "\n";
								foreach($table as $r){
									$total = number_format(round((($r['atk'] + $r['def'] + $r['sta']) / 45) * 100, 1), 1);
									$text .= "*L" .$r['level'] ."* - *" .$total ."%*: " .$r['atk'] ."/" .$r['def'] ."/" .$r['sta'] ."\n";
								}
							}
						}

						$telegram->send->chat($chat)->text($text, TRUE)->send();
						if($user->id == $this->config->item('creator') && !$telegram->is_chat_group()){
							// $telegram->send->text(json_encode($table))->send(); // DEBUG
						}
					}
				}
				exit();
			}
		}

		// PARTE 2
		$help = NULL;

		if($telegram->text_contains(["añadir", "agreg", "crear", "solicit", "pedir"]) && $telegram->text_has(["paradas", "pokeparadas"])){
			$help = "Lo siento, pero por el momento no es posible crear nuevas PokéParadas, tendrás que esperar... :(";
		}elseif($telegram->text_contains(["Niantic", "report"]) && $telegram->text_has(["link", "enlace", "página"])){
			$this->analytics->event("Telegram", "Report link");
			$help = "Link para reportar: https://goo.gl/Fy9Wt6";
		}elseif($telegram->text_has(["poli", "polis", "policía"]) && $telegram->text_contains(["juga", "movil", "móvil"])){
			$help = "Recuerda que jugar mientras conduces el coche o vas en bicicleta, está *prohibido*. "
					."Podrías provocar un accidente, así que procura jugar con seguridad! :)";
		}elseif($telegram->text_has(["significa", "quiere decir", "qué es"]) && $telegram->text_contains(["L1", "L2", "L8"])){
			$help = "Lo del *L1* es *Level 1* (*Nivel*). Si puedes, dime tu nivel y lo guardaré.\n_(Soy nivel ...)_";
		}elseif($telegram->text_has("espacio") && $telegram->text_has("mochila")){ // $telegram->text_contains(["como", "cómo"]) &&
			$help = "Tienes una mochila en la Tienda Pokemon, así que tendrás que buscar PokeMonedas si quieres comprarla. Si no, te va a tocar hacer hueco...";
		}elseif($telegram->text_has(["normas", "reglas"]) && $telegram->text_has(["entrenador"]) && $telegram->words() <= 12){
			$help = "*Normas de Entrenador de Pokémon GO*\n"
					."Pokémon GO es adecuado para jugar en un dispositivo móvil y ¡te lleva fuera, a explorar tu mundo! "
					."Por desgracia, el único límite de las trampas que pueden realizarse es la imaginación de los tramposos, pero incluyen lo siguiente:\n"
					."- Usar *software modificado o no oficial*\n"
					."- Jugar con *múltiples cuentas* (una cuenta por jugador, por favor)\n"
					."- Compartir cuentas\n"
					."- Usar *herramientas* o técnicas para *alterar o falsificar tu ubicación*, o\n"
					."- *Vender y comerciar* con las cuentas.\n"
					."Más info: http://goo.gl/KowHG8";
			$telegram->send->disable_web_page_preview(TRUE);
		}elseif($telegram->text_has(["cuáles son"]) && $telegram->text_has("legendarios")){
			$help = "Pues según la historia, serían *Articuno*, *Zapdos* y *Moltres*. Incluso hay unos Pokemon que se sabe poco de ellos... *Mew* y *Mewtwo*...";
		}elseif($telegram->text_has(["preséntate"]) && $telegram->text_has(["profe", "profesor", "oak"])){
			$help = "¡Buenas a todos! Soy el *Profesor Oak*, programado por @duhow.\n"
					."Mi objetivo es ayudar a todos los entrenadores del mundo, aunque de momento me centro en España.\n\n"
					."Conmigo podréis saber información sobre los Pokémon, cuáles son los tipos de ataques recomendados para debilitarlos rápidamente, y muchas más cosas, "
					."como por ejemplo cómo evolucionar a ciertos Pokémon o ver información de entrenadores, para saber de qué equipo son.\n\n"
					."Para poder hablar conmigo, tengo que saber de qué equipo sois, bastará con que digáis *Soy rojo*, *azul* o *amarillo*. "
					."Pero por favor, sed sinceros y nada de bromas, que yo me lo tomo muy en serio.\n"
					."Una vez hecho, podéis preguntar por ejemplo... *Debilidad contra Pikachu* y os enseñaré como funciona.\n"
					."Espero poder ayudaros en todo lo posible, ¡muchas gracias!";
		}elseif(
			($telegram->text_has(["lista", "ayuda", "ayúdame", "para qué sirve"]) && $telegram->text_has(["comando", "oak", "profe"])) or
			$telegram->text_has("/help", TRUE)
		){
			if($telegram->is_chat_group() && $telegram->user->id != $this->config->item('creator')){
				$q = $telegram->send->chat( $telegram->user->id )->text("*Ayuda del Oak:*", TRUE)->send();
				$strhelp = ($q == FALSE ? "No puedo enviarte la ayuda, escríbeme por privado primero." :
							"Te la envío por privado, " .$telegram->user->first_name .$telegram->emoji("! :happy:") );
				$telegram->send
					->notification(FALSE)
					->text($strhelp)
				->send();
				$telegram->send->chat( $telegram->user->id ); // Volver a forzar
			}
			$this->analytics->event('Telegram', 'Help');
			$help = "- Puedes preguntarme sobre la *Debilidad de Pikachu* y te responderé por privado.\n"
			 		."O si me pides que diga la *Debilidad de Pidgey aquí*, lo haré en el chat donde estés.\n"
					."También puedes preguntar *Evolución de Charizard* y te diré las fases por las que pasa.\n"
					."- Para juegos de azar, puedo *tirar los dados* o jugar al *piedra papel tijera*!\n"
					."- Si os va lento el juego o no conseguís ver o capturar Pokemon, podéis preguntar *Funciona el Pokemon?*\n"
					."- Podéis ver la *Calculadora de evolución* para saber los CP que necesitáis o tendréis para las evoluciones Pokemon.\n"
					."- También tenéis el *Mapa Pokemon* con los sitios indicados para capturar los distintos Pokemon.\n"
					."- Podéis preguntar *Quien es @usuario* (de Pokemon) para saber su equipo.\n"
					."- Si mencionáis a *@usuario* (de Pokemon), le enviaréis un mensaje directo - por si tiene el grupo silenciado, para darle un toque.\n\n"
					."¡Y muchas más cosas que vendrán próximamente!\n"
					."Cualquier duda, consulta, sugerencia o reporte de problemas podéis contactar con *mi creador*. :)";
		}elseif(
			!$telegram->text_contains("http") && // Descargas de juegos? No, gracias
			$telegram->text_has(["descarga", "enlace", "actualización"], ["de pokémon", "pokémon", "del juego"]) &&
			$telegram->words() <= 12
		){
			$google['web'] = "https://play.google.com/store/apps/details?id=com.nianticlabs.pokemongo";
			$apple['web'] =  "https://itunes.apple.com/es/app/pokemon-go/id1094591345";
			$web = file_get_contents($google['web']);
			$google['date'] = substr($web, strpos($web, "datePublished") - 32, 100);
			$google['version'] = substr($web, strpos($web, "softwareVersion") - 32, 100);
			foreach($google as $k => $v){
				$google[$k] = substr($google[$k], 0, strpos($google[$k], "</div>") + strlen("</div>"));
				$google[$k] = trim(strip_tags($google[$k]));
			}

			$web = file_get_contents($apple['web']);
			$apple['date'] = substr($web, strpos($web, "datePublished") - 16, 100);
			$apple['version'] = substr($web, strpos($web, "softwareVersion") - 16, 100);
			foreach($apple as $k => $v){
				$apple[$k] = substr($apple[$k], 0, strpos($apple[$k], "</span>") + strlen("</span>"));
				$apple[$k] = trim(strip_tags($apple[$k]));
			}

			$google['date'] = date("Y-m-d", strtotime($google['date']));
			$apple['date'] = date_create_from_format('d/m/Y', $apple['date'])->format('Y-m-d'); // HACK DMY -> YMD

			$google['days'] = floor((time() - strtotime($google['date'])) / 86400); // -> Days
			$apple['days'] = floor((time() - strtotime($apple['date'])) / 86400); // -> Days

			$google['new'] = ($google['days'] <= 1);
			$apple['new'] = ($apple['days'] <= 1);

			$dates = [0 => "hoy", 1 => "ayer"];

			$google['web'] = "https://play.google.com/store/apps/details?id=com.nianticlabs.pokemongo"; // HACK la URL se pierde ._. por eso se vuelve a agregar
			$apple['web'] =  "https://itunes.apple.com/es/app/pokemon-go/id1094591345";

			$str = "[iOS](" .$apple['web'] ."): ";
			if($apple['new']){ $str .= "*NUEVA* de " .$dates[$apple['days']] ."! "; }
			else{ $str .= "de hace " .$apple['days'] ." dias "; }
			$str .= "(" .$apple['version'] .")\n";

			$str .= "[Android](" .$google['web'] ."): ";
			if($google['new']){ $str .= "*NUEVA* de " .$dates[$google['days']] ."! "; }
			else{ $str .= "de hace " .$google['days'] ." dias "; }
			$str .= "(" .$google['version'] .")";

			$telegram->send->disable_web_page_preview(TRUE);
			$help = $str;
		}elseif($telegram->text_contains(["recompensa", "recibe", "consigue", "obtiene"]) && $telegram->text_has(["llegar", "nivel", "lvl", "level"]) && $telegram->words() <= 10){
			$items = $pokemon->items();
			$num = filter_var($telegram->text(TRUE), FILTER_SANITIZE_NUMBER_INT);
			if($num > 1 && $num <= 40){
				$this->analytics->event('Telegram', 'Trainer Rewards', $num);
				$rewards = $pokemon->trainer_rewards($num);
				if(!empty($rewards)){
					$help = "En el *nivel $num* conseguirás:\n\n";
					foreach($rewards as $r){
						$help .= "- " .str_pad($r['amount'], 2, "0", STR_PAD_LEFT) ."x " .$items[$r['item']] ."\n";
					}
				}
			}
		}elseif($telegram->text_contains("mejorar") && $telegram->text_has(["antes", "después"]) && $telegram->text_has(["evolución", "evolucionar", "evolucione"])){
			$help = "En principio es irrelevante, puedes mejorar un Pokemon antes o después de evolucionarlo sin problemas.";
		}elseif($telegram->text_has(["calculadora", "calcular", "calculo", "calcula", "tabla", "pagina", "xp", "experiencia"]) && $telegram->text_has(["evolución", "evoluciona", "evolucione", "nivel", "PC", "CP"]) && !$telegram->text_contains("IV")){
			$help = "Claro! Te refieres a la Calculadora de Evolución, verdad? http://pogotoolkit.com/";
		}elseif($telegram->text_has(["PC", "estadísticas", "estados", "ataque"]) && $telegram->text_has(["pokémon", "máximo"]) && !$telegram->text_contains(["mes"])){
			$help = "Puedes buscar las estadísticas aquí: http://pokemongo.gamepress.gg/pokemon-list";
		}elseif($telegram->text_has(["mapa", "página"]) && $telegram->text_has(["pokémon", "ciudad"]) && !$telegram->text_contains(["evoluci", "IV", "calcul"])){
			$this->analytics->event('Telegram', 'Map Pokemon');
			$help = "https://goo.gl/GZb5hd";
		}elseif($telegram->text_has(["evee"]) && !$telegram->text_has("eevee")){
			$help = "Se dice *Eevee*... ¬¬";
		}elseif($telegram->text_has(["cómo"]) && $telegram->text_has(["conseguir", "consigue"]) && $telegram->text_contains(["objeto", "incienso", "cebo", "huevo"])){
			$help = "En principio si vas a las PokeParadas y tienes suerte, también deberías de poder conseguirlos.";
		}elseif($telegram->text_contains(["calcular", "calculadora"], ["IV", "porcentaje"])){
			$this->analytics->event('Telegram', 'IV Calculator');
			// $help = "Puedes calcular las IVs de tus Pokemon en esta página: https://pokeassistant.com/main/ivcalculator";
			$help = "Si me dices los datos de tu Pokémon, te puedo calcular yo mismo los IV. :)";
		}elseif($telegram->text_contains(["tabla", "lista"]) && $telegram->text_contains(["ataque", "tipos", "tipos de ataque", "debilidad"]) && $telegram->words() < 10){
			$this->analytics->event('Telegram', 'Attack Table');
			$telegram->send
				->notification(FALSE)
				->file('photo', FCPATH .'files/attack_types.png');
			exit();
		}elseif($telegram->text_contains(["tabla", "lista"]) && $telegram->text_contains(["huevos"]) && $telegram->words() < 10){
			$this->analytics->event('Telegram', 'Egg Table');
			$telegram->send
				->notification(FALSE)
				->file('photo', FCPATH .'files/egg_list.png');
			exit();
		}elseif(
			( $telegram->text_has(["profe", "oak"]) && $telegram->text_has(["código fuente", "source"]) ) or
			$telegram->text_command("github")
		){
			$help = "Puedes inspeccionarme en github.com/duhow/ProfesorOak !\nNo me desnudes mucho que me sonrojo... " .$telegram->emoji("=P");
		}elseif($telegram->text_has(["cambiar", "cambio"]) && $telegram->text_has(["facción", "color", "equipo", "team"]) && $telegram->words() <= 12){
			$help = "Según la página oficial de Niantic, aún no es posible cambiarse de equipo. Tendrás que esperar o hacerte una cuenta nueva, pero *procura no jugar con multicuentas, está prohibido.*";
		}elseif($telegram->text_has(["cambiar", "cambio"]) && $telegram->text_has(["usuario", "nombre", "apodo", "llamo"]) && $telegram->words() <= 15){
			$help = "Si quieres cambiarte de nombre, puedes hacerlo en los *Ajustes de Pokemon GO.*\nUna vez hecho, habla con @duhow para que pueda cambiarte el nombre aquí!";
		}elseif($telegram->text_has("datos") && $telegram->text_has(["móvil", "móviles"]) && !$telegram->text_contains("http")){
			$help = "Si te has quedado sin datos, deberías pensar en cambiarte a otra compañía o conseguir una tarifa mejor. "
					."Te recomiendo que tengas al menos 4GB si vas a ponerte a jugar en serio.";
		}elseif($telegram->text_has(["os funciona", "no funciona", "no me funciona", "problema"]) && $telegram->text_has(["GPS", "ubicación"]) && !$telegram->text_contains(["fake", "bueno", "cerca", "me funciona"])){
			$help = "Si no te funciona el GPS, comprueba los ajustes de GPS. Te recomiendo que lo tengas en modo *sólo GPS*. "
					."Procura también estar en un espacio abierto, el GPS en casa no funciona a no ser que lo tengas en *modo ahorro*. \n"
					."Si sigue sin funcionar, prueba a apagar el móvil por completo, espera un par de minutos y vuelve a probar.";
		}elseif($telegram->text_has(["recomendar", "recomienda", "comprar", "aconseja"]) && $telegram->text_has("batería", ["externa", "portátil", "recargable", "extra"]) && !$telegram->text_contains(["http", "voy con", "tengo", "del port"])){
			$help = "En función de lo que vayas a jugar a Pokemon GO, puedes coger baterías pequeñas. "
					."La capacidad se mide en mAh, cuanto más tengas, más tiempo podrás jugar.\n\n"
					."Si juegas unas 2-3 horas al día, te recomiendo al menos una de 5.000 mAh. Rondan más o menos *8-12€*. "
					."Pero si quieres jugar más rato, entonces mínimo una de 10.000 o incluso 20.000 mAh. El precio va entre *20-40€*. "
					."Éstas van bien para compartirlas con la gente, por si tu amigo se queda sin batería (o tu mismo si te llega a pasar).\n"
					."Recomiendo las que son de marca *Anker* o *RAVPower*, puedes echarle un vistazo a ésta si te interesa: http://www.amazon.es/dp/B019X8EXJI";
		}elseif(
			$telegram->text_has(["evolución", "evolucionar", "evoluciones"]) &&
			$telegram->text_contains(["evee", "eevee", "jolteon", "flareon", "vaporeon"]) &&
			$telegram->text_contains(["?", "¿"]) &&
			!$telegram->text_contains(["mejor"])
		){
			$help = "Tan sólo hay que *cambiar el nombre de Eevee antes de evolucionarlo* en función del que quieras conseguir.\n\n"
					."*El truco*\n"
					."- Si quieres a *Vaporeon* (Agua), llámalo *Rainer*.\n"
					."- Si quieres a *Jolteon* (Eléctrico), llámalo *Sparky*.\n"
					."- Si quieres a *Flareon* (Fuego), llámalo *Pyro*.\n\n"
					."Pero ten en cuenta que este truco *sólo funciona una vez* por cada nombre, así que elige sabiamente...";
					// ."Estos nombres tienen una historia detrás, aunque hay que remontarse a la serie original. "
					// ."En uno de los capítulos, Ash y sus compañeros de viaje se topaban con los hermanos Eeeve, "
					// ."y cada uno de ellos tenía una de las tres evoluciones.\n_¿A que no adivinas como se llamaban los hermanos?_\n";
					// ."https://youtu.be/uZE3CwmCYcY";
		}

		if(!empty($help)){
			$telegram->send
				->notification(FALSE)
				->text($help, TRUE)
			->send();
			exit();
		}

		// PARTE 3

		if($telegram->text_contains( ["atacando", "atacan"]) && $telegram->text_contains(["gimnasio", "gym"])){

		}elseif($telegram->text_has(["debilidad", "debilidades", "luchar", "atacar"], ["contra", "hacia", "sobre", "de"]) && $telegram->words() <= 6){
			$chat = NULL;
			$filter = (strpos($telegram->text(), "/") === FALSE); // Si no hay barra, filtra.
			if(in_array($telegram->words(), [3,4]) && $telegram->text_has("aquí", FALSE)){
				$text = $telegram->words(2, $filter);
				$chat = ($telegram->is_chat_group() && $this->is_shutup() ? $telegram->user->id : $telegram->chat->id);
			}else{
				$text = $telegram->last_word($filter);
				$chat = $telegram->user->id;
			}
			$pk = $this->parse_pokemon();
			if(!empty($pk['pokemon'])){ $text = $pk['pokemon']; }
			$this->analytics->event('Telegram', 'Search Pokemon Attack', ucwords(strtolower($text)));
			$this->_poke_attack($text, $chat);
		}elseif($telegram->text_has(["evolución", "evolucionar"])){
			$chat = ($telegram->text_has("aquí") ? $telegram->chat->id : $telegram->user->id);

			$pk = $this->parse_pokemon();
			if(empty($pk['pokemon'])){ return; }

			$search = $pokemon->pokedex($pk['pokemon']);
			$this->analytics->event('Telegram', 'Search Pokemon Evolution', $search->name);

			$evol = $pokemon->evolution($search->id);
			$str = array();
			if(count($evol) == 1){ $str = "No tiene."; }
			else{
				foreach($evol as $i => $p){
					$cur = FALSE;
					if($p['id'] == $search->id){ $cur = TRUE; }

					$frase = ($cur ? $telegram->emoji(":triangle-right:") ." *" .$p['name'] ."*" : $p['name']);
					$frase .= ($p['candy'] != NULL && $p['candy'] > 0 ? " (" .$p['candy'] .$telegram->emoji(" :candy:") .")" : "");

					if(!empty($pk['cp'])){
						if(!$cur && !empty($p['evolved_from'])){ $pk['cp'] = min(floor($pk['cp'] * $p['evolved_from']['cp_multi']), $p['cp_max']); }
						if($cur or !empty($p['evolved_from'])){ $frase .= " *" .$pk['cp'] ." CP*"; }
					}
					$str[] = $frase;
				}
				$str = implode("\n", $str);

			}
			$telegram->send
				->chat( $chat )
				->notification(FALSE)
				// ->reply_to( ($chat == $telegram->chat->id) )
				->text($str, TRUE)
			->send();
		}elseif($telegram->text_has(["pokédex", "pokémon"], TRUE) or $telegram->text_command("pokedex")){
			$text = $telegram->text();
			$chat = ($telegram->text_has("aqui") && !$this->is_shutup() ? $telegram->chat->id : $telegram->user->id);
			/* if($telegram->text_has("aquí")){
				$word = $telegram->words( $telegram->words() - 2 );
			} */
			$this->_pokedex($text, $chat);

		// ---------------------
		// Utilidades varias
		// ---------------------

		//  TODO

		// ---------------------
		// Administrativo
		// ---------------------

		}elseif(
			$telegram->is_chat_group() && $telegram->words() <= 6 &&
			(
				( $telegram->text_has("está") and $telegram->text_has("aquí") ) and
				// ( ( $telegram->receive(["está", "esta"]) && $telegram->receive(["aqui", "aquí"]) ) || ( $telegram->receive(["alguno", "alguien"]) && $telegram->receive("es") ) ) &&
				( !$telegram->text_contains(["alguno", "alguien", "que"], ["es", "ha", "como", "está"]) ) // Alguien está aquí? - Alguno es....
			)
		){
			if($telegram->words() > 3){
				$find = $telegram->last_word(TRUE);
			}else{
				if(strpos($telegram->last_word(), "aqu") !== FALSE){
					$find = $telegram->words(1, TRUE);
				}else{
					$find = $telegram->words(2, TRUE);
				}
			}

			$str = "";
			$find = str_replace(["@", "?"], "", $find);
			if(empty($find) or strlen($find) < 4){ exit(); }
			if(strpos($find, "est") !== FALSE or strpos($find, "aqu") !== FALSE){ exit(); }
			$this->analytics->event('Telegram', 'Search User', $find);
			$data = $pokemon->user($find);
			if(empty($data)){
				$str = "No sé quien es. ($find)";
			}else{
				$find = $telegram->send->get_member_info($data->telegramid);
				if($find === FALSE || in_array($find['status'], ['left', 'kicked'])){
					$str = "No, no está.";
				}else{
					$str = "Si, " .$find['user']['first_name'] ." está aquí.";
				}
			}

			if(!empty($str)){
				$telegram->send
					->notification(FALSE)
					->reply_to(TRUE)
					->text($str)
				->send();
			}

			exit();
		}elseif($telegram->text_has(["poner", "actualizar", "redactar", "escribir", "cambiar"], ["las normas", "las reglas"]) && $telegram->words() <= 6 && $telegram->is_chat_group()){
			$admins = $telegram->get_admins();
			$admins[] = $this->config->item('creator');
			if(in_array($telegram->user->id, $admins)){
				$pokemon->step($telegram->user->id, 'RULES');
				$telegram->send
					->reply_to(TRUE)
					->text("De acuerdo, envíame el texto que quieres que ponga de normas.")
				->send();
				exit();
			}
		}elseif(
			$telegram->text_has(["poner", "actualizar", "redactar", "escribir", "cambiar"]) &&
			$telegram->text_has(["mensaje", "anuncio"]) &&
			$telegram->text_has(["bienvenida", "entrada"]) &&
			$telegram->words() <= 8 &&
			$telegram->is_chat_group()
		){
			$admins = $telegram->get_admins();
			$admins[] = $this->config->item('creator');
			if(in_array($telegram->user->id, $admins)){
				$pokemon->step($telegram->user->id, 'WELCOME');
				$telegram->send
					->reply_to(TRUE)
					->text("De acuerdo, envíame el texto que quieres que ponga de bienvenida.")
				->send();
				exit();
			}
		}elseif($telegram->text_has(["team", "equipo"]) && $telegram->text_has(["sóis", "hay aquí", "estáis"])){
			exit();
		}elseif($telegram->text_has("Qué", ["significa", "es"], TRUE)){
			$word = trim(strtolower($telegram->last_word(TRUE)));
			if(is_numeric($word)){ exit(); }
			$help = $pokemon->meaning($word);

			// Buscar si contiene EL/LA si no ha encontrado el $help, y repetir proceso.

			if(!empty($help) && !is_numeric($help)){
				$this->analytics->event('Telegram', 'Help Meaning', $word);
				$telegram->send
					->notification(FALSE)
					->reply_to(TRUE)
					->text($help, TRUE)
				->send();
			}
			exit();
		}elseif($telegram->text_has(["pokemon", "pokemons", "busca", "buscar", "buscame"]) && $telegram->text_contains("cerca")){
			$this->_locate_pokemon();
			exit();
		}
		// ---------------------
		// Chistes y tonterías
		// ---------------------

		$joke = NULL;

		if($telegram->text_has(["tira", "lanza", "tirar", "roll"], ["el dado", "los dados", "the dice"], TRUE) or $telegram->text_has("/dado", TRUE)){
			$this->analytics->event('Telegram', 'Games', 'Dice');
			if($pokemon->settings($telegram->chat->id, 'play_games') == FALSE){ return; }

			$num = $telegram->last_word();
			if(!is_numeric($num) or ($num < 0 or $num > 1000)){ $num = 6; } // default MAX
			$joke = "*" .mt_rand(1,$num) ."*";
		}elseif(
			( $telegram->text_has("piedra") and
			$telegram->text_has("papel") and
			$telegram->text_has(["tijera", "tijeras"]) ) or
			$telegram->text_has(["/rps", "/rpsls"], TRUE)
		){
			$this->analytics->event('Telegram', 'Games', 'RPS');
			$rps = ["Piedra", "Papel", "Tijera"];
			if($telegram->text_contains(["lagarto", "/rpsls"])){ $rps[] = "Lagarto"; }
			if($telegram->text_contains(["spock", "/rpsls"])){ $rps[] = "Spock"; }
			$n = mt_rand(0, count($rps) - 1);

			if($pokemon->settings($telegram->chat->id, 'play_games') == FALSE){ return; }
			$joke = "*" .$rps[$n] ."!*";
		}elseif($telegram->text_has(["cara o cruz", "/coin", "/flip"])){
			$this->analytics->event('Telegram', 'Games', 'Coin');
			$n = mt_rand(0, 99);
			$flip = ["Cara!", "Cruz!"];

			if($pokemon->settings($telegram->chat->id, 'play_games') == FALSE){ return; }
			$joke = "*" .$flip[$n % 2] ."*";
		}elseif($telegram->text_has(["Recarga", "/recarga"], TRUE) && $telegram->words() <= 3){
			if($pokemon->settings($telegram->chat->id, 'play_games') == FALSE){ return; }

			$shot = $pokemon->settings($telegram->chat->id, 'russian_roulette');
			$text = NULL;
			if(empty($shot)){
				$this->analytics->event('Telegram', 'Games', 'Roulette Reload');
				$shot = mt_rand(1, 6);
				$pokemon->settings($telegram->chat->id, 'russian_roulette', $shot);
				$text = "Bala puesta.";
			}else{
				if($telegram->user->id == $this->config->item('creator')){
					$pokemon->settings($telegram->chat->id, 'russian_roulette', 'DELETE');
					$this->_begin(); // HACK vigilar
				}
				$text = "Ya hay una bala. ¡*Dispara* si te atreves!";
			}
			$telegram->send
				->notification(FALSE)
				->text($text, TRUE)
			->send();
			exit();
		}elseif($telegram->text_has(["Dispara", "Disparo", "/dispara"], TRUE) && $telegram->words() <= 3){
			if($pokemon->settings($telegram->chat->id, 'play_games') == FALSE){ return; }

			$shot = $pokemon->settings($telegram->chat->id, 'russian_roulette');
			$text = NULL;
			$last = NULL; // Ultimo en disparar
			if(empty($shot)){
				$text = "No hay bala. *Recarga* antes de disparar.";
			}else{
				if($telegram->is_chat_group()){
					$last = $pokemon->settings($telegram->chat->id, 'russian_roulette_last');
					if($last == $telegram->user->id){
						$last = -1;
						$text = "Tu ya has disparado, ¡pásale el arma a otra persona!";
					}else{
						$pokemon->settings($telegram->chat->id, 'russian_roulette_last', $telegram->user->id);
					}
				}
				if($shot == 6 && $last != -1){
					$this->analytics->event('Telegram', 'Games', 'Roulette Shot');
					$pokemon->settings($telegram->chat->id, 'russian_roulette', 'DELETE');
					$text = ":die: :collision::gun:";
				}elseif($last != -1){
					$this->analytics->event('Telegram', 'Games', 'Roulette Shot');
					$pokemon->settings($telegram->chat->id, 'russian_roulette', $shot + 1);
					$faces = ["happy", "tongue", "smiley"];
					$r = mt_rand(0, count($faces) - 1);
					$text = ":" .$faces[$r] .": :cloud::gun:";
				}
				$telegram->send
					->notification(FALSE)
					->reply_to(TRUE)
					->text( $telegram->emoji($text) )
				->send();

				if($shot == 6 && $last != -1 && $telegram->is_chat_group()){
					// Implementar modo light o hard (ban)
					// Avisar al admin?
					$telegram->send->ban( $telegram->user->id );
					$pokemon->settings($telegram->chat->id, 'russian_roulette_last', 'DELETE');
				}
			}
		}elseif($telegram->text_has(["Cuéntame", "cuéntanos", "cuenta"], ["otro chiste", "un chiste"])){
			$this->_joke();
			exit();
		}elseif($telegram->text_has(["a que sí"], ["profe", "oak", "profesor"])){
			$this->analytics->event('Telegram', 'Jokes', 'Reply yes or no');
			$resp = ["¡Por supuesto que sí!",
				"Mmm... Te equivocas. _(Aunque sea por llevar la contraria)_",
				"Pues ahora me has dejado con la duda..."
			];
			$n = mt_rand(0, count($resp) - 1);
			if($pokemon->settings($telegram->chat->id, 'jokes') == FALSE){ return; }
			$joke = $resp[$n];
		}elseif($telegram->text_has("Gracias", ["profesor", "Oak", "profe"]) && !$telegram->text_has("pero", "no")){
			// "el puto amo", "que maquina eres"
			$this->analytics->event('Telegram', 'Jokes', 'Thank you');
			$frases = ["De nada, entrenador! :D", "Nada, para eso estamos! ^^", "Gracias a ti :3"];
			$n = mt_rand(0, count($frases) - 1);

			$joke = $frases[$n];
		}elseif($telegram->text_has(["Ty bro", "ty prof"])){
			if($pokemon->settings($telegram->chat->id, 'jokes') == FALSE){ return; }
			$joke = "Yeah ma nigga 8-)";
		}elseif($telegram->text_has("oak", "versión")){
			$date = (time() - filemtime(__FILE__));
			$joke = "Versión de hace " .floor($date / 60) ." minutos.";
		}elseif($telegram->text_has(["oak", "profe"], "dónde estoy") && $telegram->words() <= 4){
			// DEBUG
			if($telegram->is_chat_group()){
				$joke = "Estás en *" .$telegram->chat->title ."* ";
				if(isset($telegram->chat->username)){ $joke .= "@" .$telegram->chat->username ." "; }
				$joke .= "(" .$telegram->chat->id .").";
			}else{
				$joke = "Estás hablando por privado conmigo :)\n";
				if(isset($telegram->chat->username)){ $joke .= "@" .$telegram->chat->username ." "; }
				$joke .= "(" .$telegram->chat->id .").";
			}
		}elseif($telegram->text_has(["buenos", "buenas", "bon"], ["días", "día", "tarde", "tarda", "tardes", "noches", "nit"])){
			/* if(
				($telegram->is_chat_group() and $pokemon->settings($telegram->chat->id, 'say_hello') == TRUE) and
				($pokemon->settings($telegram->user->id, 'say_hello') != FALSE or $pokemon->settings($telegram->user->id, 'say_hello') == NULL)
			){*/
			if($this->is_shutup()){ return; }
			$joke = "Buenas a ti también, entrenador! :D";
			if($telegram->text_has(['noches', 'nit'])){
				$joke = "Buenas noches fiera, descansa bien! :)";
			}
		}elseif(
			$telegram->text_contains(["oak", "profe"]) &&
			$telegram->text_has(["cuántos", "cuándo", "qué"]) &&
			$telegram->text_contains(["años", "edad", "cumple"])
		){
			$release = strtotime("2016-07-16 14:27");
			$birthdate = strtotime("now") - $release;
			$days = floor($birthdate / (60*60*24));
			$joke = "Cumplo " .floor($days/30) ." mes y " .($days % 30) ." días. ";
			$joke .= $telegram->emoji(":)");
		}elseif($telegram->text_has(["saluda", "saludo"]) && $telegram->text_has(["profe", "profesor", "oak"])){
			if(!$this->is_shutup()){
				$joke = "Un saludo para todos mis fans! :D";
			}
		}elseif($telegram->text_has("/me", TRUE) && $telegram->words() > 1){
			$text = substr($telegram->text(), strlen("/me "));
			if(strpos($text, "/") !== FALSE){ exit(); }
			$joke = trim("*" .$telegram->user->first_name ."* " .$telegram->emoji($text));
		}elseif(
			( $telegram->text_has(["necesitas", "necesitáis"], ["novio", "un novio", "novia", "una novia", "pareja", "una pareja", "follar"]) ) or
			( $telegram->text_has("Tengo", TRUE) && $telegram->words() == 2)
		){
				$word = ($telegram->text_has("Tengo", TRUE) ? ucwords(strtolower($telegram->last_word())) : "Novia");
				$joke = "¿$word? Qué es eso, ¿se come?";
		}elseif($telegram->text_has("Team Rocket")){
			$this->analytics->event('Telegram', 'Jokes', 'Team Rocket');
			$telegram->send->notification(FALSE)->file('photo', FCPATH . "files/teamrocket.jpg", "¡¡El Team Rocket despega de nuevoooooo...!!");
			$telegram->send->notification(FALSE)->file('audio', FCPATH . "files/teamrocket.ogg");
			exit();
		}elseif($telegram->text_contains("sextape")){
			$telegram->send->notification(FALSE)->file('video', FCPATH . "files/sextape.mp4");
			exit();
		}elseif($telegram->text_has(["GTFO", "vale adiós"], TRUE)){
			// puerta revisar
			$this->analytics->event('Telegram', 'Jokes', 'GTFO');
			$telegram->send->notification(FALSE)->file('document', "BQADBAADHgEAAuK9EgOeCEDKa3fsFgI"); // Puerta
			exit();
		}elseif($telegram->text_contains(["badumtss", "ba dum tss"])){
			$this->analytics->event('Telegram', 'Jokes', 'Ba Dum Tss');
			$telegram->send->notification(FALSE)->file('document', "BQADBAADHgMAAo-zWQOHtZAjTKJW2QI");
			exit();
		}elseif($telegram->text_has(["métemela", "por el culo", "por el ano"])){
			$this->analytics->event('Telegram', 'Jokes', 'Metemela');
			$telegram->send->chat_action('record_audio')->send();
			$telegram->send->notification(FALSE)->file('voice', FCPATH . "files/metemela.ogg");
			exit();
		}elseif($telegram->text_has(["seguro", "plan"], "dental")){
			$this->analytics->event('Telegram', 'Jokes', 'Seguro dental');
			$telegram->send->chat_action('upload_video')->send();
			$telegram->send->notification(FALSE)->file('video', FCPATH . "files/seguro_dental.mp4");
			exit();
		}elseif($telegram->text_has("no paras") && $telegram->words() < 10){
			$this->analytics->event('Telegram', 'Jokes', 'Paras');
			$telegram->send->notification(FALSE)->file('photo', FCPATH . "files/paras.png");
			exit();
		}elseif($telegram->text_contains("JOHN CENA") && $telegram->words() < 10){
			$this->analytics->event('Telegram', 'Jokes', 'John Cena');
			$telegram->send->chat_action('record_audio')->send();
			$telegram->send->notification(FALSE)->file('voice', FCPATH . "files/john_cena.ogg");
			exit();
		}elseif($telegram->text_has(["soy", "eres"], ["100tifiko", "científico"])){
			$this->analytics->event('Telegram', 'Jokes', '100tifiko');
			$telegram->send->notification(FALSE)->file('sticker', 'BQADBAADFgADPngvAtG9NS3VQEf5Ag');
			exit();
		}elseif($telegram->text_has(["hola", "buenas"], ["profesor", "oak"]) && $telegram->words() <= 4){
			$this->analytics->event('Telegram', 'Jokes', 'Me gusta el dinero');
			$telegram->send->chat_action('record_audio')->send();
			$telegram->send->notification(FALSE)->file('voice', FCPATH . "files/hola_dinero.ogg");
			exit();
		}elseif($telegram->text_has(["muéstrame", "mostrar"]) && $telegram->text_has(["pokebola", "pokeball"]) && $telegram->words() <= 5){
			$this->analytics->event('Telegram', 'Jokes', 'Muestrame tu Pokebola');
			$telegram->send->chat_action('upload_audio')->send();
			$telegram->send->notification(FALSE)->file('audio', FCPATH . "files/pokebola.mp3");
			exit();
		}elseif($telegram->text_has("/fichas")){
			$this->analytics->event('Telegram', 'Jokes', 'Fichas');
			$telegram->send->notification(FALSE)->file('document', 'BQADBAADQQMAAgweZAcaoiy0cZEn5wI');
			exit();
		}elseif($telegram->text_has(["quiero", "necesito"], ["abrazo", "abrazarte", "un abrazo"])){
			$this->analytics->event('Telegram', 'Jokes', 'Hug');
			$telegram->send->notification(FALSE)->file('document', FCPATH ."files/hug.gif");
			exit();
		}elseif($telegram->text_has("corre", "corre")){
			$this->analytics->event('Telegram', 'Jokes', 'Running');
			$telegram->send->chat_action('upload_audio')->send();
			$telegram->send->notification(FALSE)->file('audio', FCPATH ."files/running.ogg");
			exit();
		}elseif($telegram->text_has(["es", "eres"], "tonto") && $telegram->words() <= 5){
			$this->analytics->event('Telegram', 'Jokes', 'Tonto');
			if($this->is_shutup()){ return; }
			if($telegram->has_reply){ $telegram->send->reply_to(FALSE); }
			$telegram->send->notification(FALSE)->file('voice', FCPATH . "files/tonto.ogg");
			exit();
		}elseif($telegram->text_has(["bug", "bugeate", "bugeado"]) && $telegram->words() <= 4){
			if(mt_rand(1, 4) == 4){
				$telegram->send->file('voice', FCPATH . 'files/modem.ogg', 'ERROR 404 PKGO_FC_CHEATS NOT_FOUND');
			}
		}elseif($telegram->text_has("qué hora", ["es", "son"]) && $telegram->text_contains("?") && $telegram->words() <= 5){
			$this->analytics->event('Telegram', 'Jokes', 'Time');
			$joke = "Son las " .date("H:i") .", una hora menos en Canarias. :)";
		}elseif($telegram->text_has("Profesor Oak", TRUE)){
			if(!$this->is_shutup()){ $joke = "Dime!"; }
		}elseif($telegram->text_has(["alguien", "alguno"]) && $telegram->text_has(["decir", "dice", "sabe"])){
			if(mt_rand(1, 7) == 7){ $joke = "pa k kieres saber eso jaja salu2"; }
		}elseif($telegram->text_has(["programado", "funcionas"]) && $telegram->text_has(["profe", "profesor", "oak", "bot"])){
			$joke = "Pues yo funciono con *PHP* (_CodeIgniter_) :)";
		}elseif($telegram->text_has(["profe", "profesor", "oak"]) && $telegram->text_has("te", ["quiero", "amo", "adoro"])){
			if(!$this->is_shutup()){ $joke = "¡Yo también te quiero! <3"; }
		}elseif($telegram->text_contains(["te la com", "te lo com", "un hijo", "me ha dolido"]) && $telegram->text_has(["oak", "profe", "bot"])){
			if($telegram->text_has("no")){
				$joke = "¿Pues entonces para que me dices nada? Gilipollas.";
			}else{
				if($this->is_shutup_jokes()){ return; }

				$joke = "Tu sabes lo que es el fiambre? Pues tranquilo, que no vas a pasar hambre... ;)";
				$telegram->send
					->notification(FALSE)
					->file('sticker', 'BQADBAADGgAD9VikAAEvUZ8dGx1_fgI');
			}
		}elseif($telegram->text_has(["transferir", "transfiere", "recicla"]) && $telegram->text_has(["pokémon"])){
			$this->analytics->event('Telegram', 'Jokes', 'Transfer Pokemon');
			$telegram->send->notification(FALSE)->file('document', FCPATH . "pidgey.gif", "Espera entrenador, que te voy a transferir un caramelo...");
		}elseif($telegram->text_has(["vas a la", "hay una", "es una"], "fiesta")){
			$this->analytics->event('Telegram', 'Jokes', 'Party');
			if($this->is_shutup()){ return; }
			$telegram->send
				->notification(FALSE)
				->caption("¿Fiesta? ¡La que te va a dar ésta!")
				->file('document', "BQADBAADpgMAAnMdZAePc-TerW2MSwI");
		}elseif($telegram->text_has("fanta") && $telegram->words() > 3){
			$this->analytics->event('Telegram', 'Jokes', 'Fanta');
			$fantas = [
				"BQADBAADLwEAAjSYQgABe8eWP7cgn9gC", // Naranja
				"BQADBAADQwEAAjSYQgABVgn9h2J6NfsC", // Limon
				"BQADBAADRQEAAjSYQgABsDEEUjdh0w8C", // Uva
				"BQADBAADRwEAAjSYQgABu1UlOqU2-8IC", // Fresa
			];
			$n = mt_rand(0, count($fantas) - 1);
			if($telegram->text_has('naranja')){ $n = 0; }
			elseif($telegram->text_has('limón')){ $n = 1; }
			elseif($telegram->text_has('uva')){ $n = 2; }
			elseif($telegram->text_has('fresa')){ $n = 3; }

			if(!$this->is_shutup()){
				$telegram->send->notification(FALSE)->file('sticker', $fantas[$n]);
			}
		}

		if(!empty($joke)){
			$telegram->send
				->notification(FALSE)
				->text($joke, TRUE)
			->send();

			exit();
		}

		// Mención de usuarios
		if(
			(
				($telegram->text_contains("@") && !$telegram->text_contains("@ ")) or
				($telegram->text_mention())
			) && $telegram->is_chat_group()
		){
			$users = array();
			preg_match_all("/[@]\w+/", $telegram->text(), $users, PREG_SET_ORDER);
			foreach($users as $i => $u){ $users[$i] = substr($u[0], 1); } // Quitamos la @
			foreach($telegram->text_mention(TRUE) as $u){
				if(is_array($u)){ $users[] = key($u); continue; }
				if($m[0] == "@"){ $users[] = substr($m, 1); }
			}

			// Quitarse usuario a si mismo
			$self = [$telegram->user->id, $telegram->user->username];
			foreach($users as $k => $u){ if(in_array($u, $self)){ unset($users[$k]); } }

			if(!empty($users)){
				if(in_array("admin", $users)){
					// FIXME Cambiar function get_admins por la integrada + array merge
					$admins = $telegram->send->get_admins();
					if(!empty($admins)){
						foreach($admins as $a){	$users[] = $a['user']['id']; }
					}
				}
				$find = $pokemon->find_users($users);
				if(!empty($find)){

					// Preparar datos - Link del chat
					$link = $pokemon->settings($telegram->chat->id, 'link_chat');
					if(!empty($link)){
						if($link[0] == "@"){ $link = "https://telegram.me/" .substr($link, 1); }
						else{ $link = "https://telegram.me/joinchat/" .$link; }
					}
					// Preparar datos - Nombre de quien escribe
					$name = (isset($telegram->user->username) ? "@" .$telegram->user->username : $telegram->user->first_name);

					foreach($find as $u){
						// Valida que el entrenador esté en el grupo
						$chat = $telegram->send->get_member_info($u['telegramid'], $telegram->chat->id);
						if($chat !== FALSE && !in_array($chat['status'], ['left', 'kicked'])){
							$str = $name ." - ";
							if(!empty($link)){ $str .= "<a href='$link'>" .$telegram->chat->title ."</a>:\n"; }
							else{ $str .= "<b>" .$telegram->chat->title ."</b>:\n"; }
							$str .= $telegram->text();

							$telegram->send
								->chat($u['telegramid'])
								->notification(TRUE)
								->disable_web_page_preview(TRUE)
								->text($str, 'HTML')
							->send();
						}
					}
				}
			}
		}

		if($telegram->text_has(["toque", "tocar"]) && $telegram->words() <= 3){
			$touch = NULL;
			if($telegram->has_reply){
				$touch = $telegram->reply_user->id;
			}elseif($telegram->text_mention()){
				$touch = $telegram->text_mention();
				if(is_array($touch)){ $touch = key($touch); }
				else{ $touch = substr($touch, 1); }
			}
			$name = (isset($telegram->user->username) ? "@" .$telegram->user->username : $telegram->user->first_name);

			$usertouch = $pokemon->user($touch);
			$req = FALSE;

			if(!empty($usertouch)){
				$req = $telegram->send
					->notification(TRUE)
					->chat($usertouch->telegramid)
					->text("$name te ha tocado.")
				->send();
			}

			$text = ($req ? $telegram->emoji(":green-check:") : $telegram->emoji(":times:"));
			$telegram->send
				->chat($telegram->user->id)
				->notification(FALSE)
				->text($text)
			->send();
		}

		if($telegram->text_has(["invertir", "invertir ubicación", "reverse"]) && $telegram->words() <= 5 && $telegram->has_reply && isset($telegram->reply->location)){
			$loc = (object) $telegram->reply->location;
			$telegram->send
				->notification(FALSE)
				->text($loc->latitude ."," .$loc->longitude)
			->send();
			exit();
		}

		// Recibir ubicación
		if($telegram->location() && !$telegram->is_chat_group()){
			$loc = implode(",", $telegram->location(FALSE));
			$pokemon->settings($user->id, 'location', $loc);
			$pokemon->step($user->id, 'LOCATION');
			$this->_step();
		}

		if($telegram->text_has(["aquí hay un", "ahi hay", "hay un"], TRUE) and $telegram->has_reply and $telegram->is_chat_group()){
			// $telegram->send->text("ke dise?")->send();
			if(isset($telegram->reply->location)){
				$loc = $telegram->reply->location['latitude'] ."," .$telegram->reply->location['longitude'];
				$pk = $this->parse_pokemon();
				if(!empty($pk['pokemon'])){
					$pokemon->settings($user->id, 'pokemon_select', $pk['pokemon']);
					$pokemon->settings($user->id, 'location', $loc);
					$pokemon->step($user->id, 'POKEMON_SEEN');
					$this->_step();
				}
			}
		}

		// Buscar coordenadas
		$loc = NULL;

		if(preg_match("/^([Cc]alcula([r]?)\s)([+-]?)(\d+.\d+)[,;]\s?([+-]?)(\d+.\d+)\s([+-]?)(\d+.\d+)[,;]\s?([+-]?)(\d+.\d+)/", $telegram->text(), $loc)){

            $l1 = [$loc[3].$loc[4], $loc[5].$loc[6]];
			$l2 = [$loc[7].$loc[8], $loc[9].$loc[10]];

            // https://stackoverflow.com/questions/10053358/measuring-the-distance-between-two-coordinates-in-php
            $r = $pokemon->location_distance($l1, $l2);

			$telegram->send->text($r)->send();
			exit();
		}

		if(preg_match("/([+-]?)(\d+.\d+)[,;]\s?([+-]?)(\d+.\d+)/", $telegram->text(), $loc)){
			$loc = $loc[0];
			if(strpos($loc, ";") !== FALSE){ $loc = explode(";", $loc); }
			elseif(strpos($loc, ",") !== FALSE){ $loc = explode(",", $loc); }

			if(count($loc) == 2){
				$this->analytics->event('Telegram', 'Parse coords');
				$telegram->send
					->location($loc[0], $loc[1])
				->send();

				// REQUIRE TOKEN API
				/* $data = http_build_query(["location" => $loc[0] ."," .$loc[1], 'f' => 'json']);
				$web = "http://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/reverseGeocode?$data";
				$data = file_get_contents($web);
				$ret = json_decode($data);
				$telegram->send
					->text(json_encode($ret))
				->send(); */
			}
		}

		// Buscar ubicación en mapa
		if($telegram->text_has(["ubicación", "mapa de"], TRUE)){
			$flags = $pokemon->user_flags($user->id);
			if(in_array('ratkid', $flags)){ exit(); }
			$text = $telegram->text();
			$text = $telegram->clean('alphanumeric-full-spaces', $text);
			if($telegram->text_has("ubicación", TRUE)){
				$text = substr($text, strlen("ubicación"));
			}elseif($telegram->text_has("mapa de", TRUE)){
				$text = substr($text, strlen("mapa de"));
			}
			$text = trim($text);
			$text = str_replace("en ", "in ", $text);
			$data = ["text" => $text, "sourceCountry" => "ESP", "f" => "json"];
			$data = http_build_query($data);
			$web = "https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/find?" .$data;
			$loc = file_get_contents($web);
			$ret = json_decode($loc);
			$str = "No lo encuentro.";
			if(!empty($ret->locations)){
				$loc = $ret->locations[0];
				$str = $loc->name ." (" .$loc->feature->attributes->Score ."%)";

				$lat = round($loc->feature->geometry->y, 6);
				$lon = round($loc->feature->geometry->x, 6);
				$telegram->send
					->location($lat, $lon)
				->send();
			}
			$this->analytics->event('Telegram', 'Map search');
			$telegram->send
				->text($str)
			->send();
		}
	}

	function _step(){
		$telegram = $this->telegram;
		$pokemon = $this->pokemon;
		$user = $telegram->user;
		$chat = $telegram->chat;

		$pokeuser = $pokemon->user($user->id);
		if(empty($pokeuser)){ return; } // HACK cuidado

		$admins = NULL;
		if($telegram->is_chat_group()){ $admins = $telegram->get_admins(); }
		$admins[] = $this->config->item('creator');

		$step = $pokeuser->step;
		switch ($step) {
			case 'POKEMON_PARSE':
				$pokes = $pokemon->pokedex();
				$s = explode(" ", $pokemon->misspell($telegram->text()));
				$data = array();
				$number = NULL;
				$hashtag = FALSE;
				// ---------
				$data['pokemon'] = NULL;
				foreach($s as $w){
					$hashtag = ($w[0] == "#" and strlen($w) > 1);
					$w = $telegram->clean('alphanumeric', $w);
					$w = strtolower($w);

					if($data['pokemon'] === NULL){
						foreach($pokes as $pk){
							if($w == strtolower($pk->name)){ $data['pokemon'] = $pk->id; break; }
						}
					}

					if(is_numeric($w)){
						// tengo un número pero no se de qué. se supone que la siguiente palabra me lo dirá.
						// a no ser que la palabra sea un "DE", en cuyo caso paso a la siguiente.
						if($hashtag == TRUE and $data['pokemon'] === NULL){
							$data['pokemon'] = (int) $w;
						}else{
							$number = (int) $w;
						}
					}

					// Buscar distancia
					if(substr($w, -1) == "m"){ // Metros
						$n = substr($w, 0, -1);
						if(!is_numeric($n) && substr($n, -1) == "k"){ // Kilometros
							$n = substr($n, 0, -1);
							if(is_numeric($n)){ $n = $n * 1000; }
						}
						if(is_numeric($n)){
							$data['distance'] = $n;
						}
					}

					// Si se escribe numero junto a palabra, separar
					$conj = ['cp', 'pc', 'hp', 'ps'];
					foreach($conj as $wf){
						if(substr($w, -2) == $wf){
							$n = substr($w, 0, -2);
							if(is_numeric($n)){
								$number = $n;
								$w = $wf;
							}
						}
					}


					$search = ['cp', 'pc', 'hp', 'ps', 'polvo', 'polvos', 'stardust', 'm', 'metro', 'km'];
					$enter = FALSE;
					foreach($search as $q){
						if(strpos($w, $q) !== FALSE){ $enter = TRUE; break; }
					}
					if($enter){
						$action = NULL;
						if(strpos($w, 'cp') !== FALSE or strpos($w, 'pc') !== FALSE){ $action = 'cp'; }
						if(strpos($w, 'hp') !== FALSE or strpos($w, 'ps') !== FALSE){ $action = 'hp'; }
						if(strpos($w, 'polvo') !== FALSE or strpos($w, 'stardust') !== FALSE){ $action = 'stardust'; }
						if(strpos($w, 'm') !== FALSE && strlen($w) == 1){ $action = 'distance'; }
						if(strpos($w, 'metro') !== FALSE){ $action = 'distance'; }
						if(strpos($w, 'km') !== FALSE && strlen($w) == 2){ $action = 'distance'; $number = $number * 1000; }

						if(strlen($w) > 2 && $number === NULL){
							// Creo que me lo ha puesto junto. Voy a sacar números...
							$number = filter_var($w, FILTER_SANITIZE_NUMBER_INT);
						}

						if(
							(!empty($number) && !empty($action)) and
							( ($action == 'hp' && $number > 5 && $number < 300) or
							($action == 'stardust' && $number > 200 && $number <= 10000) or
							($action == 'distance') or
							($number > 5 && $number < 4000) )
						){
							$data[$action] = $number;
							$number = NULL;
						}
					}
				}
				$data['attack'] = ($telegram->text_has(["ataque", "ATQ", "ATK"]));
				$data['defense'] = ($telegram->text_has(["defensa", "DEF"]));
				$data['stamina'] = ($telegram->text_has(["salud", "stamina", "estamina", "STA"]));
				$data['powered'] = ($telegram->text_has(["mejorado", "entrenado", "powered"]) && !$telegram->text_has(["sin", "no"], ["mejorar", "mejorado"]));

				if($telegram->text_has(["muy fuerte", "lo mejor", "flipando", "fuera de", "muy fuertes", "muy alto", "muy alta", "muy altas"])){ $data['ivcalc'] = [15]; }
				if($telegram->text_has(["bueno", "bastante bien", "buenas", "normal", "muy bien"])){ $data['ivcalc'] = [8,9,10,11,12]; }
				if($telegram->text_has(["bajo", "muy bajo", "poco que desear", "bien"])){ $data['ivcalc'] = [0,1,2,3,4,5,6,7]; }
				if($telegram->text_has(["fuerte", "fuertes", "excelente", "excelentes", "impresionante", "impresionantes", "alto", "alta"])){ $data['ivcalc'] = [13,14]; }

				// TODO: ataque bajo, ataque muy alto => indica valor IV.

				if($pokemon->settings($user->id, 'debug')){
					$telegram->send->text(json_encode($data))->send();
				}

				$pokemon->step($user->id, NULL);
				if($pokemon->settings($user->id, 'pokemon_return')){
					$pokemon->settings($user->id, 'pokemon_return', "DELETE");
					return $data;
				}
				break;
			case 'TIME_PARSE':
				// TODO hacer sistema
				$s = explode(" ", $telegram->text());
				$data = array();
				$number = NULL;
				$hashtag = FALSE;
				// ---------
				foreach($s as $w){
					$w = $telegram->clean('alphanumeric', $w);
					$w = strtolower($w);

					if(is_numeric($w)){
						$number = (int) $w;
						continue;
					}
				}
				break;
			case 'RULES':
				if(!$telegram->is_chat_group()){ break; }
				if(!in_array($user->id, $admins)){ $pokemon->step($user->id, NULL); break; }

				$text = json_encode($telegram->text());
				if(strlen($text) < 4){ exit(); }
				if(strlen($text) > 4000){
					$telegram->send
						->text("Buah, demasiadas normas. Relájate un poco anda ;)")
					->send();
					exit();
				}
				$this->analytics->event('Telegram', 'Set rules');
				$pokemon->settings($chat->id, 'rules', $text);
				$telegram->send
					->text("Hecho!")
				->send();
				$pokemon->step($user->id, NULL);
				break;
			case 'WELCOME':
				if(!$telegram->is_chat_group()){ break; }
				if(!in_array($user->id, $admins)){ $pokemon->step($user->id, NULL); break; }

				$text = json_encode($telegram->text());
				if(strlen($text) < 4){ exit(); }
				if(strlen($text) > 4000){
					$telegram->send
						->text("Buah, demasiado texto! Relájate un poco anda ;)")
					->send();
					exit();
				}
				$this->analytics->event('Telegram', 'Set welcome');
				$pokemon->settings($chat->id, 'welcome', $text);
				$telegram->send
					->text("Hecho!")
				->send();
				$pokemon->step($user->id, NULL);
				break;
			case 'CHOOSE_POKEMON':
				// $pk = NULL;
				$pk = $this->parse_pokemon();
				$pokemon->step($user->id, 'CHOOSE_POKEMON');
				/* if($telegram->text()){
					$pk = trim($telegram->words(0, TRUE));
					// if( preg_match('/^(#?)\d{1,3}$/', $word) ){ }
				}elseif($telegram->sticker()){
					// Decode de la lista de stickers cuál es el Pokemon.
				} */
				if(!empty($pk)){
					// $pk = $pokemon->find($pk);
					if(empty($pk['pokemon'])){
						$telegram->send
							->text("El Pokémon mencionado no existe.")
						->send();
					}else{
						$s = $pokemon->settings($user->id, 'step_action');
						$pokemon->step($user->id, $s);
						$pokemon->settings($user->id, 'pokemon_select', $pk['pokemon']);
						$this->_step(); // HACK relaunch
					}
				}
				exit();
				break;
			case 'POKEMON_SEEN':
				// Tienes que estar en el lugar para poder haber reportado el Pokemon
				// Si tienes flags TROLL, FC u otras, no podrás enviarlo.
				// Solo puedes hacer uno cada minuto.
				$pk = $pokemon->settings($user->id, 'pokemon_select');

				$pokemon->settings($user->id, 'pokemon_select', 'DELETE');
				$pokemon->settings($user->id, 'step_action', 'DELETE');

				$cd = $pokemon->settings($user->id, 'pokemon_cooldown');
				if(!empty($cd) && $cd > time()){
					$telegram->send->text("Aún no ha pasado suficiente tiempo. Espera un poco, anda. :)");
					$pokemon->step($user->id, NULL);
					exit();
				}

				if($pokemon->user_flags($user->id, ['troll', 'rager', 'bot', 'forocoches', 'hacks', 'gps', 'trollmap'])){
					$telegram->send->text("nope.")->send();
					$pokemon->step($user->id, NULL);
					exit();
				}
				$loc = explode(",", $pokemon->settings($user->id, 'location')); // FIXME cuidado con esto, si reusamos la funcion.
				$pokemon->add_found($pk, $user->id, $loc[0], $loc[1]);

				// SELECT uid, SUBSTRING(value, 1, INSTR(value, ",") - 1) AS lat, SUBSTRING(value, INSTR(value, ",") + 1) AS lng FROM `settings` WHERE LEFT(uid, 1) = '-' AND type = "location"

				$pokemon->settings($user->id, 'pokemon_cooldown', time() + 60);
				$pokemon->step($user->id, NULL);

				$this->analytics->event("Telegram", "Pokemon Seen", $pk);
				$telegram->send
					->text("Hecho! Gracias por avisar! :D")
					->keyboard()->hide(TRUE)
				->send();
				exit();
				break;
			case 'LOCATION':
				if($telegram->is_chat_group()){ return; }
				if($telegram->location()){
					$telegram->send
						->notification(FALSE)
						->reply_to(TRUE)
						->text("¿Qué quieres hacer con esa ubicación?")
						->keyboard()
							// ->row_button($telegram->emoji(":mouse: He encontrado un Pokémon!"))
							->row_button($telegram->emoji(":pin: ¡Estoy aquí!"))
							->row_button($telegram->emoji(":map: Ver los Pokémon cercanos"))
							// ->row_button($telegram->emoji(":home: Vivo aquí."))
							->row_button($telegram->emoji("Cancelar"))
							->selective(TRUE)
						->show(TRUE, TRUE)
					->send();
				}elseif($telegram->text()){
					$text = $telegram->emoji($telegram->words(0), TRUE);
					switch ($text) {
						case ':mouse:': // Pokemon Avistado
							$cd = $pokemon->settings($user->id, 'pokemon_cooldown');
							if(!empty($cd) && $cd > time()){
								$pokemon->step($user->id, NULL);
								$telegram->send
									->text("Es demasiado pronto para informar de otro Pokémon.\nTake it easy bro ;)")
									->keyboard()->hide(TRUE)
								->send();
								exit();
							}
							$pokemon->settings($user->id, 'step_action', 'POKEMON_SEEN');
							$pokemon->step($user->id, 'CHOOSE_POKEMON');
							$telegram->send
								->text("De acuerdo, dime qué Pokémon has visto aquí?")
								->keyboard()->hide(TRUE)
							->send();
							exit();
						break;
						case ':home:': // Set home
							$loc = $pokemon->settings($user->id, 'location');
							$pokemon->settings($user->id, 'location_home', $loc);
							$pokemon->step($user->id, NULL);
							$this->analytics->event('Telegram', 'Set home');
							$telegram->send
								->text("Hecho!")
								->keyboard()->hide(TRUE)
							->send();
						break;
						case ':pin:': // Set here
							$loc = $pokemon->settings($user->id, 'location');
							$here = $pokemon->settings($user->id, 'location_now', 'FULLINFO');
							$text = NULL;
							$error = FALSE;
							if(!empty($here)){
								$locs[] = explode(",", $loc);
								$locs[] = explode(",", $here->value);
								$t = time() - strtotime($here->lastupdate);
								$d = $pokemon->location_distance($locs[0], $locs[1]);
								// DEBUG $telegram->send->text($d)->send();
								if(
									($t <= 10) or
									($t <= 30 and $d >= 300) or
									($t <= 300 and $d >= 14000)
									// TODO formula km/h
								){
									$text = "¡No intentes falsificar tu ubicación! ¬¬";
									$error = TRUE;
									$pokemon->step($user->id, NULL);
								}
							}
							if(!$error){
								$this->analytics->event('Telegram', 'Set Current Location');
								$pokemon->settings($user->id, 'location_now', $loc);
								$text = "¡Hecho! ¿Quieres hacer algo más?";
							}

							if($error){ $telegram->send->keyboard()->hide(TRUE); }
							else{
								$telegram->send->keyboard()
									->row_button($telegram->emoji(":mouse: He encontrado un Pokémon!"))
									->row_button($telegram->emoji(":map: Ver los Pokémon cercanos"))
									->row_button("Cancelar")
									->selective(TRUE)
								->show(TRUE, TRUE);
							}
							$telegram->send->text($text)->send();
						break;
						case ':map:':
							$pokemon->step($user->id, NULL);
							$this->_locate_pokemon();
							exit();
						break;
						default:

						break;
					}
					exit();
				}
				break;
			case 'DUMP':
				$telegram->send->text( $telegram->dump(TRUE) )->send();
				exit();
				break;
			case 'SETNAME':
				if($telegram->words() == 1){ $this->_set_name($user->id, $telegram->text()); }
				$pokemon->step($user->id, NULL);
				break;
			default:
			break;
		}
		// exit(); // FIXME molesta. se queda comentado.
	}

	function _joke(){
		$this->analytics->event('Telegram', 'Games', 'Jokes');
		$this->last_command("JOKE");

		$jokes = $this->pokemon->settings($this->telegram->chat->id, 'jokes');
		$shut = $this->pokemon->settings($this->telegram->chat->id, 'shutup');

		$admins = array();
		if($this->telegram->is_chat_group()){ $admins = $this->telegram->get_admins(); }
		$admins[] = $this->config->item('creator');

		if(
			$this->telegram->is_chat_group() &&
			!in_array($this->telegram->user->id, $admins) &&
			( $jokes == FALSE or $shut == TRUE )
		){ return; }

		$joke = $this->pokemon->joke();

		if(filter_var($joke, FILTER_VALIDATE_URL) !== FALSE){
			// Foto
			$this->telegram->send
				->notification( !$this->telegram->is_chat_group() )
				->file('photo', $joke);
		}else{
			$this->telegram->send
				->notification( !$this->telegram->is_chat_group() )
				->text($joke, TRUE)
			->send();
		}
	}

	// function _pokedex($chat = NULL){
	function _pokedex($text = NULL, $chat = NULL){
		$telegram = $this->telegram;
		$pokemon = $this->pokemon;

		$this->last_command("POKEDEX");

		$types = $pokemon->attack_types();

		if($chat === NULL){ $chat = $telegram->chat->id; }
		if(!is_numeric($text)){
			$exp = explode(" ", $text);
			if(in_array(count($exp), [2, 3])){ // el aquí también cuenta
				$num = filter_var($exp[1], FILTER_SANITIZE_NUMBER_INT);
				if(is_numeric($num) && $num > 0 && $num < 152){ $text = $num; }
			}
			if(!is_numeric($text)){
				$poke = $this->parse_pokemon();
				$text = (!empty($poke['pokemon']) ? $poke['pokemon'] : NULL);
			}
		}

		if(empty($text)){ return; }
		$pokedex = $pokemon->pokedex($text);
		$str = "";
		if(!empty($pokedex)){
			$skills = $pokemon->skill_learn($pokedex->id);

			$str = "*#" .$pokedex->id ."* - " .$pokedex->name ."\n"
					.$types[$pokedex->type] .($pokedex->type2 ? " / " .$types[$pokedex->type2] : "") ."\n"
					."ATK " .$pokedex->attack ." - DEF " .$pokedex->defense ." - STA " .$pokedex->stamina ."\n\n";

			foreach($skills as $sk){
				$str .= "[" .$sk->attack ."/" .$sk->bars ."] - " .$sk->name_es  ."\n";
			}
		}

		if($pokedex->sticker && ($chat == $telegram->user->id)){
			$telegram->send
				->chat($chat)
				// ->notification(FALSE)
				->file('sticker', $pokedex->sticker);
		}
		if(!empty($str)){
			$telegram->send
				->chat($chat)
				// ->notification(FALSE)
				->text($str, TRUE)
			->send();
		}
	}

	function _poke_attack($text, $chat = NULL){
		$telegram = $this->telegram;
		$types = $this->pokemon->attack_types();

		$this->last_command("ATTACK");

		if($chat === NULL){ $chat = $telegram->chat->id; }
		$str = "";
		// $specs = array();

		if(strpos(strtolower($text), "missing") !== FALSE){
			$telegram->send
				->notification(FALSE)
				->reply_to(TRUE)
				->text("Lo siento, no encuentro ese número. Es que me parece que se ha perdido.")
			->send();
			exit();
		}elseif(trim(strtolower($text)) == "mime"){
			$text = "Mr. Mime";
		}elseif($text[0] == "#" && is_numeric(substr($text, 1))){ // Si es número pero con #
			$text = substr($text, 1);
		}

		// $attack contiene el primer tipo del pokemon
		$pokemon = $this->pokemon->find($text);
		if($pokemon !== FALSE){
			$str .= "#" .$pokemon['id'] ." - *" .$pokemon['name'] ."* (*" .$types[$pokemon['type']] ."*" .(!empty($pokemon['type2']) ? " / *" .$types[$pokemon['type2']] ."*" : "") .")\n";
			$primary = $pokemon['type'];
			$secondary = $pokemon['type2'];
		}else{
			$str .= "Debilidad ";
			if(strpos($text, "/") !== FALSE){
				$text = explode("/", $text);
				if(count($text) != 2){ exit(); } // Hay más de uno o algo raro.
				$primary = trim($text[0]);
				$secondary = trim($text[1]);

				$str .= "*" .ucwords($primary) ."* / *" .ucwords($secondary) ."*:\n";
			}else{
				$primary = $text;
				$str .= "*" .ucwords($primary) ."*:\n";
			}

			$primary = $this->pokemon->attack_type($primary); // Attack es toda la fila, céntrate en el ID.
			if(empty($primary)){
				// $this->telegram->send("Eso no existe, ni en el mundo Pokemon ni en la realidad.");
				exit();
			}
			$primary = $primary['id'];

			if(!empty($secondary)){
				$secondary = $this->pokemon->attack_type($secondary);
				if(!empty($secondary)){ $secondary = $secondary['id']; }
			}
		}

		// $table contiene todos las relaciones donde aparezcan alguno de los dos tipos del pokemon
		$table = $this->pokemon->attack_table($primary);
		$target[] = $primary;
		if($secondary != NULL){
			$table = array_merge($table, $this->pokemon->attack_table($secondary));
			$target[] = $secondary;
		}

		// debil, muy fuerte
		// 0.5 = poco eficaz; 2 = muy eficaz
		$list = array();
		foreach($table as $t){
			if(in_array(strtolower($t['target']), $target)){
				if($t['attack'] == 0.5){ $list[0][] = $types[$t['source']]; }
				if($t['attack'] == 2){ $list[1][] = $types[$t['source']]; }
			}
		}
		foreach($list as $k => $i){ $list[$k] = array_unique($list[$k]); } // Limpiar debilidades duplicadas
		$idex = 0;
		foreach($list[0] as $i){
			$jdex = 0;
			foreach ($list[1] as $j){
				if($i == $j){
					// $i y $j contienen el mismo tipo, hay contradicción
					unset($list[0][$idex]);
					unset($list[1][$jdex]);
				}
				$jdex++;
			}
			$idex++;
		}

		if(isset($list[0]) && count($list[0]) > 0){ $str .= "Apenas le afecta *" .implode("*, *", $list[0]) ."*.\n"; }
		if(isset($list[1]) && count($list[1]) > 0){ $str .= "Le afecta mucho *" .implode("*, *", $list[1]) ."*.\n"; }

		$telegram->send
			->chat($chat)
			->notification( ($chat == $telegram->user->id) ) // Solo si es chat privado
			->text($str, TRUE)
		->send();
	}

	function _locate_pokemon(){
		$telegram = $this->telegram;
		$pokemon = $this->pokemon;

		$distance = 500;
		$limit = 10;

		$this->last_command("POKEMAP");

		// Bloquear a trols y otros.
		if($pokemon->user_flags($telegram->user->id, ['troll', 'rager', 'spam', 'bot', 'gps', 'hacks'])){ return; }
		// Comprobar cooldown.
		if($pokemon->settings($telegram->user->id, 'pokemap_cooldown') > time()){ return; }
		// Desactivar por grupos
		if($pokemon->settings($telegram->chat->id, 'location_disable') && $telegram->user->id != $this->config->item('creator')){ return; }

		// Parsear datos Pokemon
		$pk = $this->parse_pokemon();

		if(isset($pk['distance'])){ $distance = $pk['distance']; }
		if($telegram->is_chat_group() && $pokemon->settings($telegram->chat->id, 'location')){
			// GET location del grupo
			$loc = explode(",", $pokemon->settings($telegram->chat->id, 'location'));
			$dist = $pokemon->settings($telegram->chat->id, 'location_radius');
			// Radio por defecto 5km.
			$distance = (is_numeric($dist) ? $dist : 5000);
		}else{
			// GET location
			$loc = explode(",", $pokemon->settings($telegram->user->id, 'location'));
		}
		// die();
		$list = $pokemon->spawn_near($loc, $distance, $limit, $pk['pokemon']);
		$str = "No se han encontrado Pokemon.";
		if($telegram->user->id == $this->config->item('creator')){
			$telegram->send->text("Calculando especial...")->send();
			$list = $pokemon->pokecrew($loc, $distance, $limit, $pk['pokemon']);
		}
		if(!empty($list)){
			$str = "";
			$pokedex = $pokemon->pokedex();
			$pkfind = (empty($pk['pokemon']) ? "All" : $pokedex[$pk['pokemon']]->name);
			$this->analytics->event("Telegram", "Search Pokemon Location", $pkfind);
			if(count($list) > 1){
				foreach($list as $e){
					$met = floor($e['distance']);
					if($met > 1000){ $met = round($met / 1000, 2) ."km"; }
					else{ $met .= "m"; }

					$str .= "*" .$pokedex[$e['pokemon']]->name ."* en $met" ." (" .date("d/m H:i", strtotime($e['last_seen'])) .")" ."\n";
				}
			}else{
				$e = $list[0]; // Seleccionar el primero
				$met = floor($e['distance']);
				if($met > 1000){ $met = round($met / 1000, 2) ."km"; }
				else{ $met .= "m"; }

				$str = "Tienes a *" .$pokedex[$e['pokemon']]->name ."* a $met, ve a por él!\n"
						."(" .date("d/m H:i", strtotime($e['last_seen'])) .")";
				$telegram->send->location($e['lat'], $e['lng'])->send();
			}
		}
		$time = (empty($list) ? 10 : 15); // Cooldown en función de resultado
		$pokemon->settings($telegram->user->id, 'pokemap_cooldown', time() + $time);
		$telegram->send->keyboard()->hide()->text($str, TRUE)->send();
	}

	function _set_name($user, $name, $force = FALSE){
		$telegram = $this->telegram;
		$pokemon = $this->pokemon;

		$pokeuser = $pokemon->user($user);
		if(empty($pokeuser)){ return; }
		if(!$force && !empty($pokeuser->username)){ return; }
		if($name[0] == "@"){ $name = substr($name, 1); }
		if(strlen($name) < 4){ return; }

		// si el nombre ya existe
		if($pokemon->user_exists($name)){
			$telegram->send
				->reply_to(TRUE)
				->notification(FALSE)
				->text("No puede ser, ya hay alguien que se llama *@$name* :(\nHabla con @duhow para arreglarlo.", TRUE)
			->send();
			return FALSE;
		}
		// si no existe el nombre
		else{
			$this->analytics->event('Telegram', 'Register username');
			$pokemon->update_user_data($user, 'username', $name);
			$telegram->send
				->reply_to(TRUE)
				->notification(FALSE)
				->text("De acuerdo, *@$name*!", TRUE)
			->send();
		}
		return TRUE;
	}

	function parse_pokemon(){
		$pokemon = $this->pokemon;
		$user = $this->telegram->user;

		$pokemon->settings($user->id, 'pokemon_return', TRUE);
		$pokemon->step($user->id, 'POKEMON_PARSE');
		$pk = $this->_step();
		return $pk;
	}

	function last_command($action){
		$user = $this->telegram->user->id;
		$chat = $this->telegram->chat->id;
		$pokemon = $this->pokemon;

		$command = $pokemon->settings($user, 'last_command');
		$amount = 1;
		if($command == $action){
			$count = $pokemon->settings($user, 'last_command_count');
			$add = ($user == $chat ? 0 : 1); // Solo agrega si es grupo
			$amount = (empty($count) ? 1 : ($count + $add));
		}
		$pokemon->settings($user, 'last_command', $action);
		$pokemon->settings($user, 'last_command_count', $amount);
	}

	function is_shutup(){
		$admins = $this->telegram->get_admins();
		$admins[] = $this->config->item('creator');
		$shutup = $this->pokemon->settings($this->telegram->chat->id, 'shutup');
		return ($shutup && !in_array($this->telegram->user->id, $admins));
		// $this->telegram->user->id != $this->config->item('creator')
	}

	function is_shutup_jokes(){
		$jokes = $this->pokemon->settings($this->telegram->chat->id, 'jokes');
		return ($this->is_shutup() or $jokes == FALSE);
	}

	function admins($add_creator = TRUE, $custom = NULL){
		$admins = $this->telegram->get_admins(); // Del grupo
		if($add_creator){ $admins[] = $this->config->item('creator'); }
		if($custom != NULL){
			if(!is_array($custom)){ $custom = [$custom]; }
			foreach($custom as $c){ $admins[] = $c; }
		}
		return $admins;
	}

	function _blocked(){
		exit();
	}

	function _help(){

	}

}
