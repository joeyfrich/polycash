<?php
$host_not_required = TRUE;
include(realpath(dirname(__FILE__))."/../includes/connect.php");
include(realpath(dirname(__FILE__))."/../includes/get_session.php");

die("This script is disabled.");

if ($thisuser) {
	$game_id = intval($_REQUEST['game_id']);
	
	$game = new Game($app, $game_id);
	
	if ($game) {
		$quantity = 100;
		
		for ($i=0; $i<$quantity; $i++) {
			$invitation = false;
			$game->generate_invitation($thisuser->db_user['user_id'], $invitation, false);
		}
		echo "$quantity game_invitations have been generated.";
	}
}
?>
