<?php
include("../includes/connect.php");

if ($_REQUEST['key'] == "oiwreu2490f98") {
	$q = "DELETE FROM webwallet_transactions;";
	$r = run_query($q);
	
	$q = "DELETE FROM blocks;";
	$r = run_query($q);
	
	$q = "DELETE FROM cached_rounds;";
	$r = run_query($q);
	
	$q = "ALTER TABLE blocks AUTO_INCREMENT=2;";
	$r = run_query($q);
	
	$q = "UPDATE nations SET cached_force_multiplier=16, relevant_wins=1;";
	$r = run_query($q);
	
	$q = "DELETE FROM game_nations;";
	$r = run_query($q);
	
	$q = "SELECT * FROM games;";
	$r = run_query($q);
	while ($game = mysql_fetch_array($r)) {
		$qq = "INSERT INTO blocks SET game_id='".$game['game_id']."', block_id='1', currency_mode='beta', time_created='".time()."';";
		$rr = run_query($qq);
		
		ensure_game_nations($game['game_id']);
	}
	
	$q = "SELECT * FROM user_games;";
	$r = run_query($q);
	while ($user_game = mysql_fetch_array($r)) {
		new_webwallet_transaction($user_game['game_id'], false, 100000000000, $user_game['user_id'], last_block_id($user_game['game_id']), 'giveaway');
	}
	
	echo "Great, the game has been reset!";
}
?>