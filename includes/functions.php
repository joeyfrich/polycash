<?php
function run_query($query) {
	if ($GLOBALS['show_query_errors'] == TRUE) $result = mysql_query($query) or die("Error in query: ".$query.", ".mysql_error());
	else $result = mysql_query($query) or die("Error in query");
	return $result;
}

function safe_text(&$text, $extrachars) {
	$text = mysql_real_escape_string(make_alphanumeric(strip_tags(utf8_clean($text)), $extrachars));
}

function safe_email(&$text) {
	safe_text($text, '@!#$%&*+-/=?^_`{|}~.');
}

function utf8_clean($str) {
    return iconv('UTF-8', 'UTF-8//IGNORE', $str);
}

function make_alphanumeric($string, $extrachars) {
	$allowed_chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ".$extrachars;
	$new_string = "";
	
	for ($i=0; $i<strlen($string); $i++) {
		if (is_numeric(strpos($allowed_chars, $string[$i])))
			$new_string .= $string[$i];
	}
	return $new_string;
}

function random_string($length) {
    $characters = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $string ="";

    for ($p = 0; $p < $length; $p++) {
        $string .= $characters[mt_rand(0, strlen($characters)-1)];
    }

    return $string;
}

function recaptcha_check_answer($recaptcha_privatekey, $ip_address, $g_recaptcha_response) {
	$response = json_decode(file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=".$recaptcha_privatekey."&response=".$g_recaptcha_response."&remoteip=".$ip_address), true);
	if ($response['success'] == false) return false;
	else return true;
}

function get_redirect_url($url) {
	$q = "SELECT * FROM redirect_urls WHERE url='".mysql_real_escape_string($url)."';";
	$r = run_query($q);
	if (mysql_numrows($r) > 0) {
		$redirect_url = mysql_fetch_array($r);
	}
	else {
		$q = "INSERT INTO redirect_urls SET url='".mysql_real_escape_string($url)."', time_created='".time()."';";
		$r = run_query($q);
		$redirect_url_id = mysql_insert_id();
		
		$q = "SELECT * FROM redirect_urls WHERE redirect_url_id='".$redirect_url_id."';";
		$r = run_query($q);
		$redirect_url = mysql_fetch_array($r);
	}
	return $redirect_url;
}

function mail_async($email, $from_name, $from, $subject, $message, $bcc, $cc) {
	$q = "INSERT INTO async_email_deliveries SET to_email='".mysql_real_escape_string($email)."', from_name='".$from_name."', from_email='".mysql_real_escape_string($from)."', subject='".mysql_real_escape_string($subject)."', message='".mysql_real_escape_string($message)."', bcc='".mysql_real_escape_string($bcc)."', cc='".mysql_real_escape_string($cc)."', time_created='".time()."';";
	$r = run_query($q);
	$delivery_id = mysql_insert_id();
	
	$command = "/usr/bin/php ".realpath(dirname(dirname(__FILE__)))."/scripts/async_email_deliver.php ".$delivery_id." > /dev/null 2>/dev/null &";
	exec($command);
	
	return $delivery_id;
}

function account_coin_value(&$game, $user) {
	$q = "SELECT SUM(amount) FROM transaction_IOs WHERE spend_status='unspent' AND game_id='".$game['game_id']."' AND user_id='".$user['user_id']."' AND create_block_id IS NOT NULL;";
	$r = run_query($q);
	$coins = mysql_fetch_row($r);
	$coins = intval($coins[0]);
	
	/*$q = "SELECT SUM(fee_amount) FROM webwallet_transactions WHERE from_user_id='".$user['user_id']."' AND to_user_id='".$user['user_id']."' AND block_id IS NULL;";
	$r = run_query($q);
	$fee_amount = mysql_fetch_row($r);
	$fee_amount = intval($fee_amount[0]);*/
	
	return $coins;
}

function immature_balance(&$game, $user) {
	$q = "SELECT SUM(amount) FROM transaction_IOs WHERE game_id='".$game['game_id']."' AND user_id='".$user['user_id']."' AND (create_block_id > ".(last_block_id($game['game_id'])-$game['maturity'])." OR create_block_id IS NULL) AND instantly_mature = 0;";
	$r = run_query($q);
	$sum = mysql_fetch_row($r);
	return intval($sum[0]);
}

function mature_balance(&$game, $user) {
	$q = "SELECT SUM(amount) FROM transaction_IOs WHERE spend_status='unspent' AND spend_transaction_id IS NULL AND game_id='".$game['game_id']."' AND user_id='".$user['user_id']."' AND (create_block_id <= ".(last_block_id($game['game_id'])-$game['maturity'])." OR instantly_mature = 0);";
	$r = run_query($q);
	$sum = mysql_fetch_row($r);
	return intval($sum[0]);
}

function user_coin_blocks($user_id, $game, $last_block_id) {
	$q = "SELECT SUM(amount*(".($last_block_id+1)."-create_block_id)) FROM transaction_IOs WHERE spend_status='unspent' AND spend_transaction_id IS NULL AND game_id='".$game['game_id']."' AND user_id='".$user_id."' AND (create_block_id <= ".(last_block_id($game['game_id'])-$game['maturity'])." OR instantly_mature = 1);";
	$r = run_query($q);
	$sum = mysql_fetch_row($r);
	return intval($sum[0]);
}

function current_block($game_id) {
	$q = "SELECT * FROM blocks WHERE game_id='".$game_id."' ORDER BY block_id DESC LIMIT 1;";
	$r = run_query($q);
	if (mysql_numrows($r) == 1) return mysql_fetch_array($r);
	else return false;
}

function last_block_id($game_id) {
	$block = current_block($game_id);
	if ($block) return $block['block_id'];
	else return 0;
}

function block_to_round(&$game, $mining_block_id) {
	return ceil($mining_block_id/$game['round_length']);
}

function get_site_constant($constant_name) {
	$q = "SELECT * FROM site_constants WHERE constant_name='".$constant_name."';";
	$r = run_query($q);
	if (mysql_numrows($r) == 1) {
		$constant = mysql_fetch_array($r);
		return $constant['constant_value'];
	}
	else return "";
}

function set_site_constant($constant_name, $constant_value) {
	$q = "SELECT * FROM site_constants WHERE constant_name='".$constant_name."';";
	$r = run_query($q);
	if (mysql_numrows($r) > 0) {
		$constant = mysql_fetch_array($r);
		$q = "UPDATE site_constants SET constant_value='".$constant_value."' WHERE constant_id='".$constant['constant_id']."';";
		$r = run_query($q);
	}
	else {
		$q = "INSERT INTO site_constants SET constant_name='".$constant_name."', constant_value='".$constant_value."';";
		$r = run_query($q);
	}
}

function round_voting_stats(&$game, $round_id) {
	if ($game['payout_weight'] == "coin") {
		$score_field = "coin_score";
		$sum_field = "i.amount";
	}
	else {
		$score_field = "coin_block_score";
		$sum_field = "i.coin_blocks_destroyed";
	}
	
	$last_block_id = last_block_id($game['game_id']);
	$current_round = block_to_round($game, $last_block_id+1);
	
	if ($round_id == $current_round) {
		$q = "SELECT n.*, gn.* FROM nations n, game_nations gn WHERE n.nation_id=gn.nation_id AND gn.game_id='".$game['game_id']."' ORDER BY (gn.".$score_field."+gn.unconfirmed_".$score_field.") DESC, n.nation_id ASC;";
		return run_query($q);
	}
	else {
		$q = "SELECT n.*, gn.* FROM transaction_IOs i, game_nations gn, nations n WHERE gn.nation_id=n.nation_id AND i.game_id='".$game['game_id']."' AND i.nation_id=gn.nation_id AND i.create_block_id >= ".((($round_id-1)*$game['round_length'])+1)." AND i.create_block_id <= ".($round_id*$game['round_length']-1)." GROUP BY i.nation_id ORDER BY SUM(".$sum_field.") DESC, i.nation_id ASC;";
		return run_query($q);
	}
}

function total_score_in_round(&$game, $round_id, $include_unconfirmed) {
	if ($game['payout_weight'] == "coin") $score_field = "amount";
	else $score_field = "coin_blocks_destroyed";
	
	$sum = 0;
	
	$base_q = "SELECT SUM(".$score_field.") FROM transaction_IOs WHERE game_id='".$game['game_id']."' AND nation_id > 0 AND amount > 0";
	$confirmed_q = $base_q." AND (create_block_id >= ".((($round_id-1)*$game['round_length'])+1)." AND create_block_id <= ".($round_id*$game['round_length']-1).");";
	$confirmed_r = run_query($confirmed_q);
	$confirmed_score = mysql_fetch_row($confirmed_r);
	$confirmed_score = intval($confirmed_score[0]);
	$sum += $confirmed_score;
	$returnvals['confirmed'] = $confirmed_score;
	
	if ($include_unconfirmed) {
		$q = "SELECT SUM(unconfirmed_coin_block_score), SUM(unconfirmed_coin_score) FROM game_nations WHERE game_id='".$game['game_id']."';";
		$r = run_query($q);
		$sums = mysql_fetch_array($r);
		
		if ($game['payout_weight'] == "coin") $unconfirmed_score = intval($sums['SUM(unconfirmed_coin_score)']);
		else $unconfirmed_score = intval($sums['SUM(unconfirmed_coin_block_score)']);
		
		$sum += $unconfirmed_score;
		$returnvals['unconfirmed'] = $unconfirmed_score;
	}
	$returnvals['sum'] = $sum;
	
	return $returnvals;
}

function round_voting_stats_all(&$game, $voting_round) {
	$round_voting_stats = round_voting_stats($game, $voting_round);
	$stats_all = false;
	$counter = 0;
	$nation_id_csv = "";
	$nation_id_to_rank = "";
	
	while ($stat = mysql_fetch_array($round_voting_stats)) {
		$stats_all[$counter] = $stat;
		$nation_id_csv .= $stat['nation_id'].",";
		$nation_id_to_rank[$stat['nation_id']] = $counter;
		$counter++;
	}
	if ($nation_id_csv != "") $nation_id_csv = substr($nation_id_csv, 0, strlen($nation_id_csv)-1);
	
	$q = "SELECT n.*, gn.* FROM nations n JOIN game_nations gn ON n.nation_id=gn.nation_id WHERE gn.game_id='".$game['game_id']."'";
	if ($nation_id_csv != "") $q .= " AND gn.nation_id NOT IN (".$nation_id_csv.")";
	$q .= " ORDER BY n.nation_id ASC;";
	$r = run_query($q);
	
	while ($stat = mysql_fetch_array($r)) {
		$stat['coin_score'] = 0;
		$stat['coin_block_score'] = 0;
		$stats_all[$counter] = $stat;
		$nation_id_to_rank[$stat['nation_id']] = $counter;
		$counter++;
	}
	
	$current_round = block_to_round($game, last_block_id($game['game_id'])+1);
	if ($voting_round == $current_round) $include_unconfirmed = true;
	else $include_unconfirmed = false;
	
	$score_sums = total_score_in_round($game, $voting_round, $include_unconfirmed);
	$output_arr[0] = $score_sums['sum'];
	$output_arr[1] = floor($score_sums['sum']*$game['max_voting_fraction']);
	$output_arr[2] = $stats_all;
	$output_arr[3] = $nation_id_to_rank;
	$output_arr[4] = $score_sums['confirmed'];
	$output_arr[5] = $score_sums['unconfirmed'];
	
	return $output_arr;
}

function get_round_winner($round_stats_all, $game) {
	if ($game['payout_weight'] == "coin") $score_field = "coin_score";
	else $score_field = "coin_block_score";
	
	$winner_nation_id = false;
	$winner_index = false;
	$max_score_sum = $round_stats_all[1];
	$round_stats = $round_stats_all[2];
	for ($i=0; $i<count($round_stats); $i++) {
		if (!$winner_nation_id && $round_stats[$i][$score_field] <= $max_score_sum && $round_stats[$i][$score_field] > 0) {
			$winner_nation_id = $round_stats[$i]['nation_id'];
			$winner_index = $i;
		}
	}
	if ($winner_nation_id) {
		$q = "SELECT * FROM nations WHERE nation_id='".$winner_nation_id."';";
		$r = run_query($q);
		$nation = mysql_fetch_array($r);
		
		$nation['winning_score'] = $round_stats[$winner_index][$score_field];
		
		return $nation;
	}
	else return false;
}

function current_round_table(&$game, $current_round, $user, $show_intro_text) {
	if ($game['payout_weight'] == "coin") $score_field = "coin_score";
	else $score_field = "coin_block_score";
	
	$last_block_id = last_block_id($game['game_id']);
	$current_round = block_to_round($game, $last_block_id+1);
	$block_within_round = block_id_to_round_index($game, $last_block_id+1);
	
	$round_stats_all = round_voting_stats_all($game, $current_round);
	$score_sum = $round_stats_all[0];
	$max_score_sum = $round_stats_all[1];
	$round_stats = $round_stats_all[2];
	$confirmed_score_sum = $round_stats_all[4];
	$unconfirmed_score_sum = $round_stats_all[5];
	
	$winner_nation_id = FALSE;
	
	$html = '<div id="round_table">';
	
	if ($show_intro_text) {
		if ($block_within_round != $game['round_length']) $html .= "<h2>Current Rankings - Round #".$current_round."</h2>\n";
		else {
			$winner = get_round_winner($round_stats_all, $game);
			if ($winner) $html .= "<h1>".$winner['name']." won round #".$current_round."</h1>";
			else $html .= "<h1>No winner in round #".$current_round."</h1>";
		}
		if ($last_block_id == 0) $html .= 'Currently mining the first block.<br/>';
		else $html .= 'Last block completed: #'.$last_block_id.', currently mining #'.($last_block_id+1).'<br/>';
		
		if ($block_within_round == $game['round_length']) {
			$html .= format_bignum($score_sum/pow(10,8)).' votes were cast in this round.<br/>';
			$my_votes = my_votes_in_round($game, $current_round, $user['user_id']);
			$my_winning_votes = $my_votes[0][$winner['nation_id']][$game['payout_weight']."s"];
			if ($my_winning_votes > 0) {
				$win_amount = floor($game['pos_reward']*$my_winning_votes/$winner['winning_score'])/pow(10,8);
				$html .= "You correctly ";
				if ($game['payout_weight'] == "coin") $html .= "voted ".format_bignum($my_winning_votes/pow(10,8))." coins";
				else $html .= "cast ".format_bignum($my_winning_votes/pow(10,8))." votes";
				$html .= " and won <font class=\"greentext\">+".number_format($win_amount, 2)."</font> coins.<br/>\n";
			}
			else if ($winner) {
				$html .= "You didn't cast any votes for ".$winner['name'].".<br/>\n";
			}
		}
		else {
			$html .= format_bignum($confirmed_score_sum/pow(10,8)).' confirmed and '.format_bignum($unconfirmed_score_sum/pow(10,8)).' unconfirmed votes have been cast so far, current votes count towards block '.$block_within_round.'/'.$game['round_length'].' in round #'.$current_round.'<br/>';
			$seconds_left = round(($game['round_length'] - $last_block_id%$game['round_length'] - 1)*$game['seconds_per_block']);
			$minutes_left = round($seconds_left/60);
			$html .= 'Approximately ';
			if ($minutes_left > 1) $html .= $minutes_left." minutes";
			else $html .= $seconds_left." seconds";
			$html .= ' of voting left in this round.<br/>';
		}
	}
	
	$html .= "<div class='row'>";
	
	for ($i=0; $i<count($round_stats); $i++) {
		$nation_score = $round_stats[$i][$score_field] + $round_stats[$i]['unconfirmed_'.$score_field];
		if (!$winner_nation_id && $nation_score <= $max_score_sum && $nation_score > 0) $winner_nation_id = $round_stats[$i]['nation_id'];
		$html .= '
		<div class="col-md-3">
			<div class="vote_nation_box';
			if ($nation_score > $max_score_sum) $html .=  " redtext";
			else if ($winner_nation_id == $round_stats[$i]['nation_id']) $html .=  " greentext";
			$html .='" id="vote_nation_'.$i.'" onmouseover="nation_selected('.$i.');" onclick="nation_selected('.$i.'); start_vote('.$round_stats[$i]['nation_id'].');">
				<input type="hidden" id="nation_id2rank_'.$round_stats[$i]['nation_id'].'" value="'.$i.'" />
				<input type="hidden" id="rank2nation_id_'.$i.'" value="'.$round_stats[$i]['nation_id'].'" />
				<table>
					<tr>
						<td>
							<div class="vote_nation_flag '.strtolower(str_replace(' ', '', $round_stats[$i]['name'])).'"></div>
						</td>
						<td style="width: 100%;">
							<span style="float: left;">
								<div class="vote_nation_flag_label">'.($i+1).'. '.$round_stats[$i]['name'].'</div>
							</span>
							<span style="float: right; padding-right: 5px;">';
								$pct_votes = 100*(floor(1000*$nation_score/$score_sum)/1000);
								$html .= $pct_votes;
								$html .= '%
							</span>
						</td>
					</tr>
				</table>
			</div>
		</div>';
		
		if ($ncount%4 == 1) $html .= '</div><div class="row">';
	}
	$html .= "</div>";
	$html .= "</div>";
	return $html;
}

function performance_history($user, $from_round_id, $to_round_id) {
	$html = "";
	
	$q = "SELECT * FROM games WHERE game_id='".$user['game_id']."';";
	$r = run_query($q);
	$game = mysql_fetch_array($r);
	
	$q = "SELECT * FROM cached_rounds r LEFT JOIN nations n ON r.winning_nation_id=n.nation_id WHERE r.game_id='".$game['game_id']."' AND r.round_id >= ".$from_round_id." AND r.round_id <= ".$to_round_id." ORDER BY r.round_id DESC;";
	$r = run_query($q);
	
	while ($round = mysql_fetch_array($r)) {
		$first_voting_block_id = ($round['round_id']-1)*$game['round_length']+1;
		$last_voting_block_id = $first_voting_block_id+$game['round_length']-1;
		$score_sum = 0;
		$details_html = "";
		
		$nation_scores = nation_score_in_round($game, $round['winning_nation_id'], $round['round_id']);
		
		$html .= '<div class="row" style="font-size: 13px;">';
		$html .= '<div class="col-sm-1">Round&nbsp;#'.$round['round_id'].'</div>';
		$html .= '<div class="col-sm-4">';
		if ($round['name'] != "") $html .= $round['name']." won with ".format_bignum($round['winning_score']/pow(10,8))." votes";
		else $html .= "No winner";
		$html .= '</div>';
		
		$my_votes_in_round = my_votes_in_round($game, $round['round_id'], $user['user_id']);
		$my_votes = $my_votes_in_round[0];
		$coins_voted = $my_votes_in_round[1];
		
		if ($my_votes[$round['winning_nation_id']] > 0) {
			if ($game['payout_weight'] == "coin_block") $win_text = "You correctly cast ".format_bignum($my_votes[$round['winning_nation_id']]['coin_blocks']/pow(10,8))." votes.";
			else $win_text = "You correctly voted ".format_bignum($my_votes[$round['winning_nation_id']]['coins']/pow(10,8))." coins.";
		}
		else if ($coins_voted > 0) $win_text = "You didn't vote for the winning empire.";
		else $win_text = "You didn't cast any votes.";
		
		$html .= '<div class="col-sm-5">';
		$html .= $win_text;
		$html .= ' <a href="/explorer/rounds/'.$round['round_id'].'" target="_blank">Details</a>';
		$html .= '</div>';
		
		$payout_amt = ($game['pos_reward']*$my_votes[$round['winning_nation_id']][$game['payout_weight'].'s']/$nation_scores['sum'] - $my_votes_in_round['fee_amount'])/pow(10,8);
		
		$html .= '<div class="col-sm-2">';
		$html .= '<font class="';
		if ($payout_amt >= 0) $html .= 'greentext';
		else $html .= 'redtext';
		
		$html .= '">';
		if ($payout_amt >= 0) $html .= '+';
		$html .= format_bignum($payout_amt).' EMP</font>';
		$html .= '</div>';
		
		$html .= "</div>\n";
	}
	return $html;
}

function last_voting_transaction_id($game_id) {
	$q = "SELECT transaction_id FROM webwallet_transactions WHERE game_id='".$game_id."' AND nation_id > 0 ORDER BY transaction_id DESC LIMIT 1;";
	$r = run_query($q);
	$r = mysql_fetch_row($r);
	if ($r[0] > 0) {} else $r[0] = 0;
	return $r[0];
}

function last_transaction_id($game_id) {
	$q = "SELECT transaction_id FROM webwallet_transactions WHERE game_id='".$game_id."' ORDER BY transaction_id DESC LIMIT 1;";
	$r = run_query($q);
	$r = mysql_fetch_row($r);
	if ($r[0] > 0) {} else $r[0] = 0;
	return $r[0];
}

function my_last_transaction_id($user_id, $game_id) {
	if ($user_id > 0 && $game_id > 0) {
		$start_q = "SELECT t.transaction_id FROM webwallet_transactions t, addresses a, transaction_IOs i WHERE a.address_id=i.address_id AND ";
		$end_q .= " AND a.user_id='".$user_id."' AND i.game_id='".$game_id."' ORDER BY t.transaction_id DESC LIMIT 1;";
		
		$create_r = run_query($start_q."i.create_transaction_id=t.transaction_id".$end_q);
		$create_trans_id = mysql_fetch_row($create_r);
		$create_trans_id = $create_trans_id[0];
		
		$spend_r = run_query($start_q."i.spend_transaction_id=t.transaction_id".$end_q);
		$spend_trans_id = mysql_fetch_row($spend_r);
		$spend_trans_id = $spend_trans_id[0];
		
		if ($create_trans_id > $spend_trans_id) return $create_trans_id;
		else return $spend_trans_id;
	}
	else return 0;
}

function to_significant_digits($number, $significant_digits) {
	if ($number === 0) return 0;
	if ($number < 1) $significant_digits++;
	$number_digits = (int)(log10($number));
	$returnval = (pow(10, $number_digits - $significant_digits + 1)) * floor($number/(pow(10, $number_digits - $significant_digits + 1)));
	return $returnval;
}

function format_bignum($number) {
	if ($number > 1) $number = to_significant_digits($number, 5);
	
	if ($number > pow(10, 9)) {
		return $number/pow(10, 9)."B";
	}
	else if ($number > pow(10, 6)) {
		return $number/pow(10, 6)."M";
	}
	else if ($number > pow(10, 4)) {
		return $number/pow(10, 3)."k";
	}
	else return rtrim(rtrim(number_format(sprintf('%.8F', $number), 8), '0'), ".");
}

function wallet_text_stats($thisuser, &$game, $current_round, $last_block_id, $block_within_round, $mature_balance, $immature_balance) {
	$html = "<div class=\"row\"><div class=\"col-sm-2\">Available&nbsp;funds:</div>";
	$html .= "<div class=\"col-sm-3\" style=\"text-align: right;\"><font class=\"greentext\">";
	$html .= format_bignum($mature_balance/pow(10,8));
	$html .= "</font> EmpireCoins</div></div>\n";
	if ($game['payout_weight'] == "coin_block") {
		$html .= "<div class=\"row\"><div class=\"col-sm-2\">Votes:</div><div class=\"col-sm-3\" style=\"text-align: right;\"><font class=\"greentext\">".format_bignum(user_coin_blocks($thisuser['user_id'], $game, $last_block_id)/pow(10,8))."</font> votes available</div></div>\n";
	}
	$html .= "<div class=\"row\"><div class=\"col-sm-2\">Locked&nbsp;funds:</div>";
	$html .= "<div class=\"col-sm-3\" style=\"text-align: right;\"><font class=\"redtext\">".format_bignum($immature_balance/pow(10,8))."</font> EmpireCoins</div>";
	if ($immature_balance > 0) $html .= "<div class=\"col-sm-1\"><a href=\"\" onclick=\"$('#lockedfunds_details').toggle('fast'); return false;\">Details</a></div>";
	$html .= "</div>\n";
	$html .= "Last block completed: #".$last_block_id.", currently mining #".($last_block_id+1)."<br/>\n";
	$html .= "Current votes count towards block ".$block_within_round."/".$game['round_length']." in round #".$current_round."<br/>\n";
	
	if ($immature_balance > 0) {
		$q = "SELECT * FROM webwallet_transactions t JOIN transaction_IOs i ON t.transaction_id=i.create_transaction_id LEFT JOIN nations n ON i.nation_id=n.nation_id WHERE i.game_id='".$game['game_id']."' AND i.user_id='".$thisuser['user_id']."' AND (i.create_block_id > ".(last_block_id($thisuser['game_id']) - $game['maturity'])." OR i.create_block_id IS NULL) ORDER BY i.io_id ASC;";
		$r = run_query($q);
		
		$html .= "<div style='display: none; border: 1px solid #ccc; padding: 8px; border-radius: 8px; margin-top: 8px;' id='lockedfunds_details'>";
		while ($next_transaction = mysql_fetch_array($r)) {
			$avail_block = $game['maturity'] + $next_transaction['create_block_id'] + 1;
			$seconds_to_avail = round(($avail_block - $last_block_id - 1)*$game['seconds_per_block']);
			$minutes_to_avail = round($seconds_to_avail/60);
			
			if ($next_transaction['transaction_desc'] == "votebase") $html .= "You won ";
			$html .= "<font class=\"greentext\">".round($next_transaction['amount']/(pow(10, 8)), 2)."</font> ";
			
			if ($next_transaction['create_block_id'] == "") {
				$html .= "coins were just ";
				if ($next_transaction['nation_id'] > 0) {
					$html .= "voted for ".$next_transaction['name'];
				}
				else $html .= "spent";
				$html .= ". This transaction is not yet confirmed.";
			}
			else {
				if ($next_transaction['transaction_desc'] == "votebase") $html .= "coins in block ".$next_transaction['create_block_id'].". Coins";
				else $html .= "coins received in block #".$next_transaction['create_block_id'];
				
				$html .= " can be spent in block #".$avail_block.". (Approximately ";
				if ($minutes_to_avail > 1) $html .= $minutes_to_avail." minutes";
				else $html .= $seconds_to_avail." seconds";
				$html .= "). ";
				if ($next_transaction['nation_id'] > 0) {
					$html .= "You voted for ".$next_transaction['name']." in round #".block_to_round($game, $next_transaction['create_block_id']).". ";
				}
			}
			$html .= "(tx: <a target=\"_blank\" href=\"/explorer/transactions/".$next_transaction['tx_hash']."\">".$next_transaction['transaction_id']."</a>)<br/>\n";
		}
		$html .= "</div>\n";
	}
	return $html;
}

function vote_details_general($mature_balance) {
	$html = '
	<div class="row">
		<div class="col-xs-6">Your balance:</div>
		<div class="col-xs-6 greentext">'.number_format(floor($mature_balance/pow(10,5))/1000, 2).' EMP</div>
	</div>	';
	return $html;
}

function vote_nation_details($nation, $rank, $nation_score, $score_sum, $losing_streak) {
	$html .= '
	<div class="row">
		<div class="col-xs-6">Current&nbsp;rank:</div>
		<div class="col-xs-6">'.$rank.date("S", strtotime("1/".$rank."/2015")).'</div>
	</div>
	<div class="row">
		<div class="col-xs-6">Votes:</div>
		<div class="col-xs-5">'.number_format($nation_score/pow(10,8), 2).' votes</div>
	</div>
	<div class="row">
		<div class="col-xs-6">Percent&nbsp;of&nbsp;votes:</div>
		<div class="col-xs-5">';
	if ($score_sum > 0) $html .= (ceil(100*100*$nation_score/$score_sum)/100);
	else $html .= '0';
	$html .= '%</div>
	</div>
	<div class="row">
		<div class="col-xs-6">Last&nbsp;win:</div>
		<div class="col-xs-5">';
	if ($losing_streak > 0) $html .= ($losing_streak+1).'&nbsp;rounds&nbsp;ago';
	else $html .= "Last&nbsp;round";
	$html .= '
		</div>
	</div>';
	return $html;
}

function generate_user_addresses($user_game) {
	$q = "SELECT * FROM nations n WHERE NOT EXISTS(SELECT * FROM addresses a WHERE a.user_id='".$user_game['user_id']."' AND a.game_id='".$user_game['game_id']."' AND a.nation_id=n.nation_id) ORDER BY n.nation_id ASC;";
	$r = run_query($q);
	
	while ($nation = mysql_fetch_array($r)) {
		if ($user_game['game_type'] == "real") {
			$qq = "SELECT * FROM addresses WHERE nation_id='".$nation['nation_id']."' AND game_id='".$user_game['game_id']."' AND is_mine=1 AND user_id IS NULL;";
			$rr = run_query($qq);
			
			if (mysql_numrows($rr) > 0) {
				$address = mysql_fetch_array($rr);
				
				$qq = "UPDATE addresses SET user_id='".$user_game['user_id']."' WHERE address_id='".$address['address_id']."';";
				$rr = run_query($qq);
			}
		}
		else {
			$new_address = "E";
			$rand1 = rand(0, 1);
			$rand2 = rand(0, 1);
			if ($rand1 == 0) $new_address .= "e";
			else $new_address .= "E";
			if ($rand2 == 0) $new_address .= strtoupper($nation['address_character']);
			else $new_address .= $nation['address_character'];
			$new_address .= random_string(31);
			
			$qq = "INSERT INTO addresses SET game_id='".$user_game['game_id']."', nation_id='".$nation['nation_id']."', user_id='".$user_game['user_id']."', address='".$new_address."', time_created='".time()."';";
			$rr = run_query($qq);
		}
	}
	
	$q = "SELECT * FROM addresses WHERE nation_id IS NULL AND game_id='".$user_game['game_id']."' AND user_id='".$user_game['user_id']."';";
	$r = run_query($q);
	
	if (mysql_numrows($r) == 0) {
		if ($user_game['game_type'] == "real") {
			$q = "SELECT * FROM addresses WHERE nation_id IS NULL AND game_id='".$user_game['game_id']."' AND is_mine=1 AND user_id IS NULL;";
			$r = run_query($q);
			if (mysql_numrows($r) > 0) {
				$address = mysql_fetch_array($r);
				
				$q = "UPDATE addresses SET user_id='".$user_game['game_id']."' WHERE address_id='".$address['address_id']."';";
				$r = run_query($q);
			}
		}
		else {
			$new_address = "Ex";
			$new_address .= random_string(32);
			
			$qq = "INSERT INTO addresses SET game_id='".$user_game['game_id']."', user_id='".$user_game['user_id']."', address='".$new_address."', time_created='".time()."';";
			$rr = run_query($qq);
		}
	}
}

function user_address_id($game_id, $user_id, $nation_id) {
	$q = "SELECT * FROM addresses WHERE game_id='".$game_id."' AND user_id='".$user_id."'";
	if ($nation_id) $q .= " AND nation_id='".$nation_id."'";
	else $q .= " AND nation_id IS NULL";
	$q .= ";";
	$r = run_query($q);
	
	if (mysql_numrows($r) > 0) {
		$address = mysql_fetch_array($r);
		return $address['address_id'];
	}
	else return false;
}

function new_payout_transaction(&$game, $voting_round, $block_id, $winning_nation, $winning_score) {
	$log_text = "";
	
	if ($game['payout_weight'] == "coin") $score_field = "amount";
	else $score_field = "coin_blocks_destroyed";
	
	$q = "INSERT INTO webwallet_transactions SET game_id='".$game['game_id']."', transaction_desc='votebase', amount=0, block_id='".$block_id."', time_created='".time()."';";
	$r = run_query($q);
	$transaction_id = mysql_insert_id();
	
	// Loop through the correctly voted UTXOs
	$q = "SELECT * FROM transaction_IOs i, users u WHERE i.game_id='".$game['game_id']."' AND i.user_id=u.user_id AND i.create_block_id >= ".((($voting_round-2)*$game['round_length'])+1)." AND i.create_block_id <= ".(($voting_round-1)*$game['round_length']-1)." AND i.nation_id=".$winning_nation.";";
	$r = run_query($q);
	
	$total_paid = 0;
	
	while ($input = mysql_fetch_array($r)) {
		$payout_amount = floor($game['pos_reward']*$input[$score_field]/$winning_score);
		
		$total_paid += $payout_amount;
		
		$qq = "INSERT INTO transaction_IOs SET spend_status='unspent', instantly_mature=0, game_id='".$game['game_id']."', user_id='".$input['user_id']."', address_id='".$input['address_id']."', nation_id=NULL, create_transaction_id='".$transaction_id."', amount='".$payout_amount."', create_block_id='".$block_id."';";
		$rr = run_query($qq);
		$output_id = mysql_insert_id();
		
		$qq = "UPDATE transaction_IOs SET payout_io_id='".$output_id."' WHERE io_id='".$input['io_id']."';";
		$rr = run_query($qq);
		
		$log_text .= "Pay ".$payout_amount/(pow(10,8))." EMP to ".$input['username']."<br/>\n";
	}
	
	$q = "UPDATE webwallet_transactions SET amount='".$total_paid."' WHERE transaction_id='".$transaction_id."';";
	$r = run_query($q);
	
	$returnvals[0] = $transaction_id;
	$returnvals[1] = $log_text;
	
	return $returnvals;
}

function new_betbase_transaction(&$game, $round_id, $mining_block_id, $winning_nation) {
	$log_text = "";
	
	$q = "INSERT INTO webwallet_transactions SET game_id='".$game['game_id']."', transaction_desc='betbase', block_id='".($mining_block_id-1)."', time_created='".time()."';";
	$r = run_query($q);
	$transaction_id = mysql_insert_id();
	
	$bet_mid_q = "transaction_IOs i, addresses a WHERE i.game_id='".$game['game_id']."' AND i.address_id=a.address_id AND a.bet_round_id = ".$round_id." AND i.create_block_id <= ".round_to_last_betting_block($game, $round_id);
	
	$total_burned_q = "SELECT SUM(i.amount) FROM ".$bet_mid_q.";";
	$total_burned_r = run_query($total_burned_q);
	$total_burned = mysql_fetch_row($total_burned_r);
	$total_burned = $total_burned[0];
	
	if ($total_burned > 0) {
		$winners_burned_q = "SELECT SUM(i.amount) FROM ".$bet_mid_q;
		if ($winning_nation) $winners_burned_q .= " AND bet_nation_id=".$winning_nation.";";
		else $winners_burned_q .= " AND bet_nation_id IS NULL;";
		$winners_burned_r = run_query($winners_burned_q);
		$winners_burned = mysql_fetch_row($winners_burned_r);
		$winners_burned = $winners_burned[0];
		
		$win_multiplier = 0;
		if ($winners_burned > 0) $win_multiplier = floor(pow(10,8)*$total_burned/$winners_burned)/pow(10,8);
		
		$log_text .= $total_burned/pow(10,8)." coins should be paid to the winning bettors (x".$win_multiplier.").<br/>\n";
		
		if ($winners_burned > 0) {
			$bet_winners_q = "SELECT * FROM ".$bet_mid_q." AND bet_nation_id=".$winning_nation.";";
			$bet_winners_r = run_query($bet_winners_q);
			
			$betbase_sum = 0;
			
			while ($bet_winner = mysql_fetch_array($bet_winners_r)) {
				$win_amount = floor($bet_winner['amount']*$win_multiplier);
				$payback_address = bet_transaction_payback_address($bet_winner['create_transaction_id']);
				
				if ($payback_address) {
					$qq = "INSERT INTO transaction_IOs SET spend_status='unspent', instantly_mature=0, game_id='".$game['game_id']."', user_id='".$payback_address['user_id']."', address_id='".$payback_address['address_id']."'";
					if ($payback_address['nation_id'] > 0) $qq .= ", nation_id=".$payback_address['nation_id'];
					$qq .= ", create_transaction_id='".$transaction_id."', amount='".$win_amount."', create_block_id='".($mining_block_id-1)."';";
					$rr = run_query($qq);
					$output_id = mysql_insert_id();
					
					$qq = "UPDATE transaction_IOs SET payout_io_id='".$output_id."' WHERE io_id='".$bet_winner['io_id']."';";
					$rr = run_query($qq);
					
					$log_text .= "Pay ".$win_amount/(pow(10,8))." coins to ".$payback_address['address']." for winning the bet.<br/>\n";
					
					$betbase_sum += $win_amount;
				}
				else $log_text .= "No payback address was found for transaction #".$bet_winner['create_transaction_id']."<br/>\n";
			}
			
			$q = "UPDATE webwallet_transactions SET amount='".$betbase_sum."' WHERE transaction_id='".$transaction_id."';";
			$r = run_query($q);
		}
		else $log_text .= "None of the bettors predicted this outcome!<br/>\n";
	}
	else {
		$log_text .= "No one placed losable bets on this round.<br/>\n";
		$q = "DELETE FROM webwallet_transactions WHERE transaction_id='".$transaction_id."';";
		$r = run_query($q);
		$transaction_id = false;
	}
	
	$returnvals[0] = $transaction_id;
	$returnvals[1] = $log_text;
	
	return $returnvals;
}

function new_webwallet_multi_transaction(&$game, $nation_ids, $amounts, $from_user_id, $to_user_id, $block_id, $type, $io_ids, $address_ids, $remainder_address_id, $transaction_fee) {
	if (!$type || $type == "") $type = "transaction";
	
	$amount = $transaction_fee;
	for ($i=0; $i<count($amounts); $i++) {
		$amount += $amounts[$i];
	}
	
	if ($type == "giveaway") $instantly_mature = 1;
	else $instantly_mature = 0;
	
	$from_user['user_id'] = $from_user_id;
	
	$account_value = account_coin_value($game, $from_user);
	$immature_balance = immature_balance($game, $from_user);
	$mature_balance = mature_balance($game, $from_user);
	$utxo_balance = false;
	if ($io_ids) {
		$q = "SELECT SUM(amount) FROM transaction_IOs WHERE io_id IN (".implode(",", $io_ids).");";
		$r = run_query($q);
		$utxo_balance = mysql_fetch_row($r);
		$utxo_balance = intval($utxo_balance[0]);
	}
	
	$raw_txin = array();
	$raw_txout = array();
	$affected_input_ids = array();
	$created_input_ids = array();
	
	if ($type == "giveaway" || $type == "votebase" || $type == "coinbase") $amount_ok = true;
	else if ($utxo_balance == $amount || (!$io_ids && $amount <= $mature_balance)) $amount_ok = true;
	else $amount_ok = false;
	
	if ($amount_ok && (count($nation_ids) == count($amounts) || ($type == "bet" && count($amounts) == count($address_ids)))) {
		$q = "INSERT INTO webwallet_transactions SET game_id='".$game['game_id']."', fee_amount='".$transaction_fee."'";
		if ($game['game_type'] == "simulation") $q .= ", tx_hash='".random_string(64)."'";
		if ($nation_id) $q .= ", nation_id=NULL";
		$q .= ", transaction_desc='".$type."', amount=".$amount.", ";
		if ($from_user_id) $q .= "from_user_id='".$from_user_id."', ";
		if ($to_user_id) $q .= "to_user_id='".$to_user_id."', ";
		if ($type == "bet") {
			$qq = "SELECT bet_round_id FROM addresses WHERE address_id='".$address_ids[0]."';";
			$rr = run_query($qq);
			$bet_round_id = mysql_fetch_row($rr);
			$bet_round_id = $bet_round_id[0];
			$q .= "bet_round_id='".$bet_round_id."', ";
		}
		$q .= "address_id=NULL";
		if ($block_id !== false) $q .= ", block_id='".$block_id."'";
		$q .= ", time_created='".time()."';";
		$r = run_query($q);
		$transaction_id = mysql_insert_id();
		
		$overshoot_amount = 0;
		$overshoot_return_addr_id = $remainder_address_id;
		
		if ($type == "giveaway" || $type == "votebase" || $type == "coinbase") {}
		else {
			$q = "SELECT *, io.address_id AS address_id, io.amount AS amount FROM transaction_IOs io JOIN webwallet_transactions t ON io.create_transaction_id=t.transaction_id WHERE io.spend_status='unspent' AND io.user_id='".$from_user_id."' AND io.game_id='".$game['game_id']."' AND (io.create_block_id <= ".(last_block_id($game['game_id'])-$game['maturity'])." OR io.instantly_mature=1)";
			if ($io_ids) $q .= " AND io.io_id IN (".implode(",", $io_ids).")";
			$q .= " ORDER BY io.amount ASC;";
			$r = run_query($q);
			
			$input_sum = 0;
			$coin_blocks_destroyed = 0;
			
			$ref_block_id = last_block_id($game['game_id'])+1;
			$ref_cbd = 0;
			
			while ($transaction_input = mysql_fetch_array($r)) {
				if ($input_sum < $amount) {
					$qq = "UPDATE transaction_IOs SET spend_transaction_id='".$transaction_id."'";
					if ($block_id !== false) $qq .= ", spend_status='spent', spend_block_id='".$block_id."'";
					$qq .= " WHERE io_id='".$transaction_input['io_id']."';";
					$rr = run_query($qq);
					
					if (!$overshoot_return_addr_id) $overshoot_return_addr_id = intval($transaction_input['address_id']);
					
					$input_sum += $transaction_input['amount'];
					$ref_cbd += ($ref_block_id-$transaction_input['create_block_id'])*$transaction_input['amount'];
					
					if ($block_id !== false) $coin_blocks_destroyed += ($block_id - $transaction_input['create_block_id'])*$transaction_input['amount'];
					
					$affected_input_ids[count($affected_input_ids)] = $transaction_input['io_id'];
					
					$raw_txin[count($raw_txin)] = array(
						"txid"=>$transaction_input['tx_hash'],
						"vout"=>intval($transaction_input['out_index'])
					);
				}
			}
			
			$overshoot_amount = $input_sum - $amount;
			
			$qq = "UPDATE webwallet_transactions SET ref_block_id='".$ref_block_id."', ref_coin_blocks_destroyed='".$ref_cbd."' WHERE transaction_id='".$transaction_id."';";
			$rr = run_query($qq);
		}
		
		$output_error = false;
		for ($i=0; $i<count($amounts); $i++) {
			if (!$output_error) {
				if ($address_ids) {
					if (count($address_ids) == count($amounts)) $address_id = $address_ids[$i];
					else $address_id = $address_ids[0];
				}
				else $address_id = user_address_id($game['game_id'], $to_user_id, $nation_ids[$i]);
				
				if ($address_id) {
					$q = "SELECT * FROM addresses WHERE address_id='".$address_id."';";
					$r = run_query($q);
					$address = mysql_fetch_array($r);
					
					$q = "INSERT INTO transaction_IOs SET spend_status='";
					if ($instantly_mature == 1) $q .= "unspent";
					else $q .= "unconfirmed";
					$q .= "', out_index='".$i."', ";
					if ($to_user_id) $q .= "user_id='".$to_user_id."', ";
					if ($block_id !== false) {
						$output_cbd = floor($coin_blocks_destroyed*($amounts[$i]/$input_sum));
						$q .= "coin_blocks_destroyed='".$output_cbd."', ";
					}
					$q .= "instantly_mature='".$instantly_mature."', game_id='".$game['game_id']."', ";
					if ($block_id !== false) $q .= "create_block_id='".$block_id."', ";
					$q .= "address_id='".$address_id."', nation_id='".$address['nation_id']."', create_transaction_id='".$transaction_id."', amount='".$amounts[$i]."';";
					$r = run_query($q);
					$created_input_ids[count($created_input_ids)] = mysql_insert_id();
					
					$raw_txout[$address['address']] = $amounts[$i]/pow(10,8);
				}
				else $output_error = true;
			}
		}
		
		if ($output_error) {
			cancel_transaction($transaction_id, $affected_input_ids, false);
			return false;
		}
		else {
			if ($overshoot_amount > 0) {
				$q = "SELECT * FROM addresses WHERE address_id='".$overshoot_return_addr_id."';";
				$r = run_query($q);
				$overshoot_address = mysql_fetch_array($r);
				
				$q = "INSERT INTO transaction_IOs SET spend_status='unconfirmed', game_id='".$game['game_id']."', ";
				if ($block_id !== false) {
					$overshoot_cbd = floor($coin_blocks_destroyed*($overshoot_amount/$input_sum));
					$q .= "coin_blocks_destroyed='".$overshoot_cbd."', ";
				}
				$q .= "user_id='".$from_user_id."', address_id='".$overshoot_return_addr_id."', nation_id='".$overshoot_address['nation_id']."', create_transaction_id='".$transaction_id."', ";
				if ($block_id !== false) $q .= "create_block_id='".$block_id."', ";
				$q .= "amount='".$overshoot_amount."';";
				$r = run_query($q);
				$created_input_ids[count($created_input_ids)] = mysql_insert_id();
				
				$raw_txout[$overshoot_address['address']] = $overshoot_amount/pow(10,8);
			}
			
			$rpc_error = false;
			
			if ($game['game_type'] == "real") {
				require_once(realpath(dirname(__FILE__))."/jsonRPCClient.php");
				$empirecoin_rpc = new jsonRPCClient('http://'.$GLOBALS['coin_rpc_user'].':'.$GLOBALS['coin_rpc_password'].'@127.0.0.1:'.$GLOBALS['coin_testnet_port'].'/');
				try {
					$raw_transaction = $empirecoin_rpc->createrawtransaction($raw_txin, $raw_txout);
					$signed_raw_transaction = $empirecoin_rpc->signrawtransaction($raw_transaction);
					$decoded_transaction = $empirecoin_rpc->decoderawtransaction($signed_raw_transaction['hex']);
					$tx_hash = $decoded_transaction['txid'];
					$q = "UPDATE webwallet_transactions SET tx_hash='".$tx_hash."' WHERE transaction_id='".$transaction_id."';";
					$r = run_query($q);
					$verified_tx_hash = $empirecoin_rpc->sendrawtransaction($signed_raw_transaction['hex']);
				} catch (Exception $e) {
					$rpc_error = true;
					cancel_transaction($transaction_id, $affected_input_ids, $created_input_ids);
					return false;
				}
			}
			
			update_nation_scores($game);
			return $transaction_id;
		}
	}
	else return false;
}
function update_nation_scores(&$game) {
	$last_block_id = last_block_id($game['game_id']);
	$round_id = block_to_round($game, $last_block_id+1);
	$q = "UPDATE game_nations n INNER JOIN (
		SELECT nation_id, SUM(amount) sum_amount, SUM(coin_blocks_destroyed) sum_cbd FROM transaction_IOs 
		WHERE game_id='".$game['game_id']."' AND create_block_id >= ".((($round_id-1)*$game['round_length'])+1)." AND amount > 0
		GROUP BY nation_id
	) i ON n.nation_id=i.nation_id SET n.coin_score=i.sum_amount, n.coin_block_score=i.sum_cbd WHERE n.game_id='".$game['game_id']."';";
	$r = run_query($q);
	
	$q = "UPDATE game_nations SET unconfirmed_coin_score=0, unconfirmed_coin_block_score=0 WHERE game_id='".$game['game_id']."';";
	$r = run_query($q);
	
	if ($game['payout_weight'] == "coin") {
		$q = "UPDATE game_nations n INNER JOIN (
			SELECT nation_id, SUM(amount) sum_amount FROM transaction_IOs 
			WHERE game_id='".$game['game_id']."' AND create_block_id IS NULL AND amount > 0
			GROUP BY nation_id
		) i ON n.nation_id=i.nation_id SET n.unconfirmed_coin_score=i.sum_amount WHERE n.game_id='".$game['game_id']."';";	
		$r = run_query($q);
	}
	else {
		$q = "UPDATE game_nations n INNER JOIN (
			SELECT io.nation_id, SUM((t.ref_coin_blocks_destroyed+(".($last_block_id+1)."-t.ref_block_id)*t.amount)*io.amount/t.amount) sum_cbd FROM transaction_IOs io JOIN webwallet_transactions t ON io.create_transaction_id=t.transaction_id
			WHERE t.game_id='".$game['game_id']."' AND io.create_block_id IS NULL AND io.amount > 0
			GROUP BY io.nation_id
		) i ON n.nation_id=i.nation_id SET n.unconfirmed_coin_block_score=i.sum_cbd WHERE n.game_id='".$game['game_id']."';";
		$r = run_query($q);
	}
}
function cancel_transaction($transaction_id, $affected_input_ids, $created_input_ids) {
	$q = "DELETE FROM webwallet_transactions WHERE transaction_id='".$transaction_id."';";
	$r = run_query($q);
	
	if (count($affected_input_ids) > 0) {
		$q = "UPDATE transaction_IOs SET spend_status='unspent', spend_transaction_id=NULL, spend_block_id=NULL WHERE io_id IN (".implode(",", $affected_input_ids).")";
		$r = run_query($q);
	}
	
	if ($created_input_ids && count($created_input_ids) > 0) {
		$q = "DELETE FROM transaction_IOs WHERE io_id IN (".implode(",", $created_input_ids).");";
		$r = run_query($q);
	}
}

function nation_score_in_round(&$game, $nation_id, $round_id) {
	if ($game['payout_weight'] == "coin") $score_field = "amount";
	else $score_field = "coin_blocks_destroyed";
	
	$mining_block_id = last_block_id($game['game_id'])+1;
	$current_round_id = block_to_round($game, $mining_block_id);
	
	if ($current_round_id == $round_id) {
		$q = "SELECT SUM(coin_score), SUM(unconfirmed_coin_score), SUM(coin_block_score), SUM(unconfirmed_coin_block_score) FROM game_nations WHERE nation_id='".$nation_id."' AND game_id='".$game['game_id']."';";
		$r = run_query($q);
		$sums = mysql_fetch_array($r);
		if ($game['payout_weight'] == "coin") {
			$confirmed_score = $sums['SUM(coin_score)'];
			$unconfirmed_score = $sums['SUM(unconfirmed_coin_score)'];
		}
		else {
			$confirmed_score = $sums['SUM(coin_block_score)'];
			$unconfirmed_score = $sums['SUM(unconfirmed_coin_block_score)'];
		}
	}
	else {
		$q = "SELECT SUM(".$score_field.") FROM transaction_IOs WHERE game_id='".$game['game_id']."' AND ";
		$q .= "(create_block_id >= ".((($round_id-1)*$game['round_length'])+1)." AND create_block_id <= ".($round_id*$game['round_length']-1).") AND nation_id='".$nation_id."';";
		$r = run_query($q);
		$confirmed_score = mysql_fetch_row($r);
		$confirmed_score = $confirmed_score[0];
		$unconfirmed_score = 0;
	}
	if (!$confirmed_score) $confirmed_score = 0;
	if (!$unconfirmed_score) $unconfirmed_score = 0;
	
	return array('confirmed'=>$confirmed_score, 'unconfirmed'=>$unconfirmed_score, 'sum'=>$confirmed_score+$unconfirmed_score);
}

function my_votes_in_round(&$game, $round_id, $user_id) {
	$q = "SELECT n.*, SUM(t.fee_amount) AS fee_amount, SUM(io.amount), SUM(io.coin_blocks_destroyed) FROM transaction_IOs io JOIN nations n ON io.nation_id=n.nation_id JOIN webwallet_transactions t ON io.create_transaction_id=t.transaction_id WHERE io.game_id='".$game['game_id']."' AND io.create_block_id >= ".((($round_id-1)*$game['round_length'])+1)." AND io.create_block_id <= ".($round_id*$game['round_length']-1)." AND io.user_id='".$user_id."' GROUP BY io.nation_id ORDER BY n.nation_id ASC;";
	$r = run_query($q);
	$coins_voted = 0;
	$coin_blocks_voted = 0;
	$my_votes = array();
	$fee_sum = 0;
	while ($votesum = mysql_fetch_array($r)) {
		$my_votes[$votesum['nation_id']]['coins'] = $votesum['SUM(io.amount)'];
		$my_votes[$votesum['nation_id']]['coin_blocks'] = $votesum['SUM(io.coin_blocks_destroyed)'];
		$coins_voted += $votesum['SUM(io.amount)'];
		$coin_blocks_voted += $votesum['SUM(io.coin_blocks_destroyed)'];
		$fee_sum += $votesum['fee_amount'];
	}
	$returnvals[0] = $my_votes;
	$returnvals[1] = $coins_voted;
	$returnvals[2] = $coin_blocks_voted;
	$returnvals['fee_amount'] = $fee_sum;
	return $returnvals;
}

function my_votes_table(&$game, $round_id, $user) {
	$html = "";
	
	$confirmed_html = "";
	$num_confirmed = 0;
	
	$unconfirmed_html = "";
	$num_unconfirmed = 0;
	
	if ($game['payout_weight'] == "coin") $score_field = "amount";
	else $score_field = "coin_blocks_destroyed";
	
	$q = "SELECT n.*, t.transaction_id, t.fee_amount, io.spend_status, SUM(io.amount), SUM(io.coin_blocks_destroyed) FROM transaction_IOs io JOIN webwallet_transactions t ON io.create_transaction_id=t.transaction_id JOIN nations n ON io.nation_id=n.nation_id WHERE io.game_id='".$game['game_id']."' AND (io.create_block_id >= ".((($round_id-1)*$game['round_length'])+1)." AND io.create_block_id <= ".($round_id*$game['round_length']-1).") AND io.user_id='".$user['user_id']."' GROUP BY io.nation_id ORDER BY SUM(io.amount) DESC;";
	$r = run_query($q);
	while ($my_vote = mysql_fetch_array($r)) {
		$color = "green";
		$num_votes = $my_vote['SUM(io.'.$score_field.')'];
		$nation_scores = nation_score_in_round($game, $my_vote['nation_id'], $round_id);
		$expected_payout = floor($game['pos_reward']*($my_vote['SUM(io.'.$score_field.')']/$nation_scores['sum'])-$my_vote['fee_amount'])/pow(10,8);
		if ($expected_payout < 0) $expected_payout = 0;
		
		$confirmed_html .= "<div class=\"row\">";
		$confirmed_html .= "<div class=\"col-sm-4 ".$color."text\">".$my_vote['name']."</div>";
		$confirmed_html .= "<div class=\"col-sm-4 ".$color."text\"><a target=\"_blank\" href=\"/explorer/transactions/".$my_vote['transaction_id']."\">".format_bignum($num_votes/pow(10,8), 2)." votes</a></div>";
		$confirmed_html .= "<div class=\"col-sm-4 ".$color."text\">+".format_bignum($expected_payout)." EMP</div>";
		$confirmed_html .= "</div>\n";
		
		$num_confirmed++;
	}
	
	$q = "SELECT n.*, io.amount, t.transaction_id, t.fee_amount, t.amount AS transaction_amount, t.ref_block_id, t.ref_coin_blocks_destroyed FROM transaction_IOs io JOIN webwallet_transactions t ON io.create_transaction_id=t.transaction_id JOIN nations n ON io.nation_id=n.nation_id WHERE io.game_id='".$game['game_id']."' AND io.create_block_id IS NULL AND io.user_id='".$user['user_id']."' ORDER BY io.amount DESC;";
	$r = run_query($q);
	while ($my_vote = mysql_fetch_array($r)) {
		$color = "yellow";
		$nation_scores = nation_score_in_round($game, $my_vote['nation_id'], $round_id);
		
		if ($game['payout_weight'] != "coin") {
			$transaction_cbd = $my_vote['ref_coin_blocks_destroyed'] + ((1+last_block_id($game['game_id']))-$my_vote['ref_block_id'])*$my_vote['transaction_amount'];
			$num_votes = floor($transaction_cbd*($my_vote['amount']/$my_vote['transaction_amount']));
			$expected_payout = floor($game['pos_reward']*($num_votes/$nation_scores['sum'])-$my_vote['fee_amount'])/pow(10,8);
		}
		else {
			$num_votes = $my_vote['SUM(io.'.$score_field.')'];
			$expected_payout = floor($game['pos_reward']*($my_vote['SUM(io.'.$score_field.')']/$nation_scores['sum'])-$my_vote['fee_amount'])/pow(10,8);
		}
		if ($expected_payout < 0) $expected_payout = 0;
		
		$unconfirmed_html .= "<div class=\"row\">";
		$unconfirmed_html .= "<div class=\"col-sm-4 ".$color."text\">".$my_vote['name']."</a></div>";
		$unconfirmed_html .= "<div class=\"col-sm-4 ".$color."text\"><a target=\"_blank\" href=\"/explorer/transactions/".$my_vote['transaction_id']."\">".format_bignum($num_votes/pow(10,8), 2)." votes</a></div>";
		$unconfirmed_html .= "<div class=\"col-sm-4 ".$color."text\">+".format_bignum($expected_payout)." EMP</div>";
		$unconfirmed_html .= "</div>\n";
		
		$num_unconfirmed++;
	}
	
	if ($num_unconfirmed + $num_confirmed > 0) {
		$html .= '
		<div class="my_votes_table">
			<div class="row my_votes_header">
				<div class="col-sm-4">Empire</div>
				<div class="col-sm-4">Amount</div>
				<div class="col-sm-4">Payout</div>
			</div>
			'.$unconfirmed_html.$confirmed_html.'
		</div>';
	}
	else $html .= "You haven't voted yet in this round.";
	
	return $html;
}

function set_user_active($user_id) {
	$q = "UPDATE users SET logged_in=1, last_active='".time()."' WHERE user_id='".$user_id."';";
	$r = run_query($q);
}

function initialize_vote_nation_details(&$game, $nation_id2rank, $score_sum, $user_id) {
	$html = "";
	$nation_q = "SELECT * FROM nations n INNER JOIN game_nations gn ON n.nation_id=gn.nation_id WHERE gn.game_id='".$game['game_id']."' ORDER BY n.nation_id ASC;";
	$nation_r = run_query($nation_q);
	
	$nation_id = 0;
	while ($nation = mysql_fetch_array($nation_r)) {
		$rank = $nation_id2rank[$nation['nation_id']]+1;
		if ($game['payout_weight'] == "coin") $voting_sum = $nation['coin_score'];
		else $voting_sum = $nation['coin_block_score'];
		$html .= '
		<div style="display: none;" class="modal fade" id="vote_confirm_'.$nation['nation_id'].'">
			<div class="modal-dialog">
				<div class="modal-content">
					<div class="modal-body">
						<h2>Vote for '.$nation['name'].'</h2>
						<div id="vote_nation_details_'.$nation['nation_id'].'">
							'.vote_nation_details($nation, $rank, $voting_sum, $score_sum, $nation['losing_streak']).'
						</div>
						<div id="vote_details_'.$nation['nation_id'].'"></div>
						<div class="redtext" id="vote_error_'.$nation['nation_id'].'"></div>
					</div>
					<div class="modal-footer">
						<button class="btn btn-primary" id="vote_confirm_btn_'.$nation['nation_id'].'" onclick="add_nation_to_vote('.$nation['nation_id'].', \''.$nation['name'].'\');">Add '.$nation['name'].' to my vote</button>
						<button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
					</div>
				</div>
			</div>
		</div>';
		$n_counter++;
	}
	return $html;
}

function try_apply_invite_key($user_id, $invite_key) {
	$invite_key = mysql_real_escape_string($invite_key);
	
	$q = "SELECT * FROM invitations WHERE invitation_key='".$invite_key."';";
	$r = run_query($q);
	if (mysql_numrows($r) == 1) {
		$invitation = mysql_fetch_array($r);
		
		if ($invitation['used'] == 0 && $invitation['used_user_id'] == "" && $invitation['used_time'] == 0) {
			$qq = "UPDATE invitations SET used_user_id='".$user_id."' WHERE invitation_id='".$invitation['invitation_id']."';";
			$rr = run_query($qq);
			return true;
		}
		else return false;
	}
	else return false;
}

function ensure_user_in_game($user_id, $game_id) {
	$q = "SELECT * FROM user_games ug JOIN games g ON ug.game_id=g.game_id WHERE ug.user_id='".$user_id."' AND ug.game_id='".$game_id."';";
	$r = run_query($q);
	
	if (mysql_numrows($r) == 0) {
		$q = "INSERT INTO user_games SET user_id='".$user_id."', game_id='".$game_id."';";
		$r = run_query($q);
		$user_game_id = mysql_insert_id();
		
		$q = "SELECT * FROM user_games ug JOIN games g ON ug.game_id=g.game_id WHERE ug.user_game_id='".$user_game_id."';";
		$r = run_query($q);
		$user_game = mysql_fetch_array($r);
	}
	else {
		$user_game = mysql_fetch_array($r);
	}
	
	if ($user_game['strategy_id'] > 0) {}
	else {
		$q = "INSERT INTO user_strategies SET game_id='".$game_id."', user_id='".$user_game['user_id']."';";
		$r = run_query($q);
		$strategy_id = mysql_insert_id();
		
		$q = "SELECT * FROM users u, user_games g, user_strategies s WHERE u.user_id=g.user_id AND u.game_id=g.game_id AND g.strategy_id=s.strategy_id AND u.user_id='".$user_id."';";
		$r = run_query($q);
		
		if (mysql_numrows($r) == 1) {
			$copy_strategy = mysql_fetch_array($r);
			
			$strategy_vars = explode(",", "voting_strategy,aggregate_threshold,by_rank_ranks,api_url,min_votesum_pct,max_votesum_pct,min_coins_available");
			$q = "UPDATE user_strategies SET ";
			for ($i=0; $i<count($strategy_vars); $i++) {
				$q .= $strategy_vars[$i]."='".mysql_real_escape_string($copy_strategy[$strategy_vars[$i]])."', ";
			}
			for ($i=1; $i<=9; $i++) {
				$q .= "vote_on_block_".$i."=".$copy_strategy['vote_on_block_'.$i].", ";
			}
			for ($i=1; $i<=16; $i++) {
				$q .= "nation_pct_".$i."=".$copy_strategy['nation_pct_'.$i].", ";
			}
			$q = substr($q, 0, strlen($q)-2)." WHERE strategy_id='".$strategy_id."';";
			$r = run_query($q);
		}
		
		$q = "UPDATE user_games SET strategy_id='".$strategy_id."' WHERE user_game_id='".$user_game['user_game_id']."';";
		$r = run_query($q);
	}
	
	generate_user_addresses($user_game);
}

function new_block($game_id) {
	// This function only runs for games with game_type='simulation'
	
	$q = "SELECT * FROM games WHERE game_id='".$game_id."';";
	$r = run_query($q);
	$game = mysql_fetch_array($r);
	
	$log_text = "";
	$last_block_id = last_block_id($game['game_id']);
	
	$q = "INSERT INTO blocks SET game_id='".$game['game_id']."', block_id='".($last_block_id+1)."', time_created='".time()."';";
	$r = run_query($q);
	$last_block_id = mysql_insert_id();
	
	$q = "SELECT * FROM blocks WHERE internal_block_id='".$last_block_id."';";
	$r = run_query($q);
	$block = mysql_fetch_array($r);
	$last_block_id = $block['block_id'];
	$mining_block_id = $last_block_id+1;
	
	$voting_round = block_to_round($game, $mining_block_id);
	
	$log_text .= "Created block $last_block_id<br/>\n";
	
	// Include all unconfirmed TXs in the just-mined block
	$q = "SELECT * FROM webwallet_transactions t JOIN transaction_IOs io ON t.transaction_id=io.create_transaction_id WHERE t.game_id='".$game['game_id']."' AND io.spend_status='unconfirmed' GROUP BY t.transaction_id;";
	$r = run_query($q);
	$fee_sum = 0;
	
	while ($unconfirmed_tx = mysql_fetch_array($r)) {
		$qq = "SELECT SUM(amount) FROM transaction_IOs io JOIN addresses a ON io.address_id=a.address_id WHERE io.create_transaction_id='".$unconfirmed_tx['transaction_id']."';";
		$rr = run_query($qq);
		$coins_out = mysql_fetch_row($rr);
		$coins_out = intval($coins_out[0]);
		
		$qq = "SELECT SUM(amount) FROM transaction_IOs io JOIN addresses a ON io.address_id=a.address_id WHERE io.spend_transaction_id='".$unconfirmed_tx['transaction_id']."';";
		$rr = run_query($qq);
		$coins_in = mysql_fetch_row($rr);
		$coins_in = intval($coins_in[0]);
		
		if ($coins_in > 0 && $coins_in >= $coins_out) {
			$fee_amount = $coins_in - $coins_out;
			
			$qq = "SELECT * FROM transaction_IOs WHERE spend_transaction_id='".$unconfirmed_tx['transaction_id']."';";
			$rr = run_query($qq);
			$total_coin_blocks_created = 0;
			while ($input_utxo = mysql_fetch_array($rr)) {
				$coin_blocks_created = ($last_block_id - $input_utxo['create_block_id'])*$input_utxo['amount'];
				$qqq = "UPDATE transaction_IOs SET coin_blocks_created='".$coin_blocks_created."' WHERE io_id='".$input_utxo['io_id']."';";
				$rrr = run_query($qqq);
				$total_coin_blocks_created += $coin_blocks_created;
			}
			
			$qq = "SELECT SUM(amount) FROM transaction_IOs io JOIN addresses a ON io.address_id=a.address_id WHERE io.create_transaction_id='".$unconfirmed_tx['transaction_id']."' AND a.nation_id > 0;";
			$rr = run_query($qq);
			$voted_coins_out = mysql_fetch_row($rr);
			$voted_coins_out = $voted_coins_out[0];
			
			$cbd_per_coin_out = floor(pow(10,8)*$total_coin_blocks_created/$voted_coins_out)/pow(10,8);
			
			$qq = "SELECT * FROM transaction_IOs io JOIN addresses a ON io.address_id=a.address_id WHERE io.create_transaction_id='".$unconfirmed_tx['transaction_id']."' AND a.nation_id > 0;";
			$rr = run_query($qq);
			
			while ($output_utxo = mysql_fetch_array($rr)) {
				$coin_blocks_destroyed = floor($cbd_per_coin_out*$output_utxo['amount']);
				$qqq = "UPDATE transaction_IOs SET coin_blocks_destroyed='".$coin_blocks_destroyed."' WHERE io_id='".$output_utxo['io_id']."';";
				$rrr = run_query($qqq);
			}
			
			$qq = "UPDATE webwallet_transactions SET block_id='".$last_block_id."' WHERE transaction_id='".$unconfirmed_tx['transaction_id']."';";
			$rr = run_query($qq);
			
			$qq = "UPDATE transaction_IOs SET spend_status='unspent', create_block_id='".$last_block_id."' WHERE create_transaction_id='".$unconfirmed_tx['transaction_id']."';";
			$rr = run_query($qq);
			
			$qq = "UPDATE transaction_IOs SET spend_status='spent', spend_block_id='".$last_block_id."' WHERE spend_transaction_id='".$unconfirmed_tx['transaction_id']."';";
			$rr = run_query($qq);
			
			$fee_sum += $fee_amount;
		}
	}
	
	$mined_address = create_or_fetch_address($game, "Ex".random_string(32), true, false, false);
	$mined_transaction_id = new_webwallet_multi_transaction($game, array(false), array($game['pow_reward']+$fee_sum), false, false, $last_block_id, "coinbase", false, array($mined_address['address_id']), false, 0);
	
	if ($GLOBALS['outbound_email_enabled'] && $game['game_type'] == "real") {
		// Send notifications for coins that just became available
		$q = "SELECT u.* FROM users u, transaction_IOs i WHERE i.game_id='".$game['game_id']."' AND i.user_id=u.user_id AND u.notification_preference='email' AND u.notification_email != '' AND i.create_block_id='".($last_block_id - $game['maturity'])."' AND i.amount > 0 GROUP BY u.user_id;";
		$r = run_query($q);
		while ($notify_user = mysql_fetch_array($r)) {
			$account_value = account_coin_value($game, $notify_user);
			$immature_balance = immature_balance($game, $notify_user);
			$mature_balance = mature_balance($game, $notify_user);
			
			if ($mature_balance >= $account_value*$notify_user['aggregate_threshold']/100) {
				$subject = number_format($mature_balance/pow(10,8), 5)." empirecoins are now available to vote.";
				$message = "<p>Some of your coins just became available.</p>";
				$message .= "<p>You currently have ".format_bignum($mature_balance/pow(10,8))." coins available to vote. To cast a vote, please log in:</p>";
				$message .= "<p><a href=\"".$GLOBALS['base_url']."/wallet/\">".$GLOBALS['base_url']."/wallet/</a></p>";
				$message .= "<p>This message was sent by ".$GLOBALS['site_domain']."<br/>To disable these notifications, please log in and then click \"Settings\"";
				
				$delivery_id = mail_async($notify_user['notification_email'], $GLOBALS['site_name'], "noreply@".$GLOBALS['site_domain'], $subject, $message, "", "");
				
				$log_text .= "A notification of new coins available has been sent to ".$notify_user['notification_email'].".<br/>\n";
			}
		}
	}
	
	// Run payouts
	if ($last_block_id%$game['round_length'] == 0) {
		$log_text .= "<br/>Running payout on voting round #".($voting_round-1).", it's now round ".$voting_round."<br/>\n";
		$round_voting_stats = round_voting_stats_all($game, $voting_round-1);
		
		$score_sum = $round_voting_stats[0];
		$max_score_sum = $round_voting_stats[1];
		$nation_id2rank = $round_voting_stats[3];
		$round_voting_stats = $round_voting_stats[2];
		
		$winning_nation = FALSE;
		$winning_votesum = 0;
		$winning_score = 0;
		$rank = 1;
		for ($rank=1; $rank<=get_site_constant('num_voting_options'); $rank++) {
			$nation_id = $round_voting_stats[$rank-1]['nation_id'];
			$nation_rank2db_id[$rank] = $nation_id;
			$nation_scores = nation_score_in_round($game, $nation_id, $voting_round-1);
			
			if ($nation_scores['sum'] > $max_score_sum) {}
			else if (!$winning_nation && $nation_scores['sum'] > 0) {
				$winning_nation = $nation_id;
				$winning_votesum = $nation_scores['sum'];
				$winning_score = $nation_scores['sum'];
			}
		}
		
		$log_text .= "Total votes: ".($score_sum/(pow(10, 8)))."<br/>\n";
		$log_text .= "Cutoff: ".($max_score_sum/(pow(10, 8)))."<br/>\n";
		
		$q = "UPDATE game_nations SET coin_score=0, coin_block_score=0, losing_streak=losing_streak+1 WHERE game_id='".$game['game_id']."';";
		$r = run_query($q);
		
		if ($winning_nation) {
			$q = "UPDATE game_nations SET losing_streak=0 WHERE game_id='".$game['game_id']."' AND nation_id='".$winning_nation."';";
			$r = run_query($q);
			
			$log_text .= $round_voting_stats[$nation_id2rank[$winning_nation]]['name']." wins with ".($winning_votesum/(pow(10, 8)))." EMP voted.<br/>";
			$payout_response = new_payout_transaction($game, $voting_round, $last_block_id, $winning_nation, $winning_votesum);
			$transaction_id = $payout_response[0];
			$log_text .= "Payout response: ".$payout_response[1];
			$log_text .= "<br/>\n";
		}
		else $log_text .= "No winner<br/>";
		
		if ($game['losable_bets_enabled'] == 1) {
			$betbase_response = new_betbase_transaction($game, $voting_round-1, $last_block_id+1, $winning_nation);
			$log_text .= $betbase_response[1];
		}
		
		$q = "UPDATE webwallet_transactions t JOIN transaction_IOs io ON t.transaction_id=io.create_transaction_id SET t.block_id='".($last_block_id+1)."', io.create_block_id='".($last_block_id+1)."' WHERE t.block_id IS NULL;";
		$r = run_query($q);
		
		$q = "INSERT INTO cached_rounds SET game_id='".$game['game_id']."', round_id='".($voting_round-1)."', payout_block_id='".$last_block_id."'";
		if ($winning_nation) $q .= ", winning_nation_id='".$winning_nation."'";
		$q .= ", winning_score='".$winning_score."', score_sum='".$score_sum."', time_created='".time()."'";
		for ($position=1; $position<=16; $position++) {
			$q .= ", position_".$position."='".$nation_rank2db_id[$position]."'";
		}
		$q .= ";";
		$r = run_query($q);
	}
	
	update_nation_scores($game);
	
	return $log_text;
}

function apply_user_strategies(&$game) {
	$log_text = "";
	$last_block_id = last_block_id($game['game_id']);
	$mining_block_id = $last_block_id+1;
	
	$current_round_id = block_to_round($game, $mining_block_id);
	$block_of_round = block_id_to_round_index($game, $mining_block_id);
	
	if ($block_of_round != $game['round_length']) {
		$q = "SELECT * FROM users u INNER JOIN user_games g ON u.user_id=g.user_id INNER JOIN user_strategies s ON g.strategy_id=s.strategy_id WHERE g.game_id='".$game['game_id']."'";
		$q .= " AND (s.voting_strategy='by_rank' OR s.voting_strategy='by_nation' OR s.voting_strategy='api')";
		//$q .= " AND s.vote_on_block_".$block_of_round."=1";
		$q .= " ORDER BY RAND();";
		$r = run_query($q);
		
		$log_text .= "Applying user strategies for block #".$mining_block_id.", looping through ".mysql_numrows($r)." users.<br/>";
		
		while ($strategy_user = mysql_fetch_array($r)) {
			$user_coin_value = account_coin_value($game, $strategy_user);
			$immature_balance = immature_balance($game, $strategy_user);
			$mature_balance = $user_coin_value - $immature_balance;
			$free_balance = $mature_balance - $strategy_user['min_coins_available']*pow(10,8);
			
			if ($mature_balance > 0) {
				if ($strategy_user['voting_strategy'] == "api") {
					if ($GLOBALS['api_proxy_url']) $api_client_url = $GLOBALS['api_proxy_url'].urlencode($strategy_user['api_url']);
					else $api_client_url = $strategy_user['api_url'];
					
					$api_result = file_get_contents($api_client_url);
					$api_obj = json_decode($api_result);
					
					if ($api_obj->recommendations && count($api_obj->recommendations) > 0 && in_array($api_obj->recommendation_unit, array('coin','percent'))) {
						$input_error = false;
						$input_io_ids = array();
						
						if ($api_obj->input_utxo_ids) {
							if (count($api_obj->input_utxo_ids) > 0) {
								for ($i=0; $i<count($api_obj->input_utxo_ids); $i++) {
									if (!$input_error) {
										$utxo_id = intval($api_obj->input_utxo_ids[$i]);
										if (strval($utxo_id) === strval($api_obj->input_utxo_ids[$i])) {
											$utxo_q = "SELECT *, io.user_id AS io_user_id, a.user_id AS address_user_id FROM transaction_IOs io JOIN addresses a ON io.address_id=a.address_id WHERE io.io_id='".$utxo_id."' AND io.game_id='".$game['game_id']."';";
											$utxo_r = run_query($utxo_q);
											if (mysql_numrows($utxo_r) == 1) {
												$utxo = mysql_fetch_array($utxo_r);
												if ($utxo['io_user_id'] == $strategy_user['user_id'] && $utxo['address_user_id'] == $strategy_user['user_id']) {
													if (!$utxo['spend_transaction_id'] && $utxo['spend_status'] == "unspent" && $utxo['create_block_id'] !== "") {
														$input_io_ids[count($input_io_ids)] = $utxo['io_id'];
													}
													else {
														$input_error = true;
														$log_text .= "Error, you specified an input which has already been spent.";
													}
												}
												else {
													$input_error = true;
													$log_text .= "Error, you specified an input which is not associated with your user account.";
												}
											}
											else {
												$input_error = true;
												$log_text .= "Error, an invalid transaction input was specified.";
											}
										}
										else {
											$input_error = true;
											$log_text .= "Error, an invalid transaction input was specified.";
										}
									}
								}
							}
							else {
								$input_error = true;
								$log_text .= "Error, invalid format for transaction inputs.";
							}
						}
						if (count($input_io_ids) > 0 && $input_error == false) {}
						else $input_io_ids = false;
						
						$amount_error = false;
						$amount_sum = 0;
						$empire_id_error = false;
						
						$log_text .= $strategy_user['username']." has ".$mature_balance/pow(10,8)." coins available, hitting url: ".$strategy_user['api_url']."<br/>\n";
						
						for ($rec_id=0; $rec_id<count($api_obj->recommendations); $rec_id++) {
							if ($api_obj->recommendations[$rec_id]->recommended_amount && $api_obj->recommendations[$rec_id]->recommended_amount > 0 && intval($api_obj->recommendations[$rec_id]->recommended_amount) == $api_obj->recommendations[$rec_id]->recommended_amount) $amount_sum += $api_obj->recommendations[$rec_id]->recommended_amount;
							else $amount_error = true;
							
							if ($api_obj->recommendations[$rec_id]->empire_id >= 0 && $api_obj->recommendations[$rec_id]->empire_id < 16) {}
							else $empire_id_error = true;
						}
						
						if ($api_obj->recommendation_unit == "coin") {
							if ($amount_sum <= $mature_balance) {}
							else $amount_error = true;
						}
						else {
							if ($amount_sum <= 100) {}
							else $amount_error = true;
						}
						
						if ($amount_error) {
							$log_text .= "Error, an invalid amount was specified.";
						}
						else if ($empire_id_error) {
							$log_text .= "Error, one of the empire IDs was invalid.";
						}
						else {
							$vote_nation_ids = array();
							$vote_amounts = array();
							
							for ($rec_id=0; $rec_id<count($api_obj->recommendations); $rec_id++) {
								if ($api_obj->recommendation_unit == "coin") $vote_amount = $api_obj->recommendations[$rec_id]->recommended_amount;
								else $vote_amount = floor($mature_balance*$api_obj->recommendations[$rec_id]->recommended_amount/100);
								
								$vote_nation_id = $api_obj->recommendations[$rec_id]->empire_id + 1;
								
								$vote_nation_ids[count($vote_nation_ids)] = $vote_nation_id;
								$vote_amounts[count($vote_amounts)] = $vote_amount;
								
								$log_text .= "Vote ".$vote_amount." for ".$vote_nation_id."<br/>\n";
							}
							
							$transaction_id = new_webwallet_multi_transaction($game, $vote_nation_ids, $vote_amounts, $strategy_user['user_id'], $strategy_user['user_id'], false, 'transaction', $input_io_ids, false, false, false);
							
							if ($transaction_id) $log_text .= "Added transaction $transaction_id<br/>\n";
							else $log_text .= "Failed to add transaction.<br/>\n";
						}
					}
				}
				else {
					$pct_free = 100*$mature_balance/$user_coin_value;
					
					if ($pct_free >= $strategy_user['aggregate_threshold']) {
						$round_stats = round_voting_stats_all($game, $current_round_id);
						$score_sum = $round_stats[0];
						$ranked_stats = $round_stats[2];
						$nation_id2rank = $round_stats[3];
						
						$nation_pct_sum = 0;
						$skipped_pct_points = 0;
						$skipped_nations = "";
						$num_nations_skipped = 0;
						
						if ($strategy_user['voting_strategy'] == "by_rank") $by_rank_ranks = explode(",", $strategy_user['by_rank_ranks']);
						
						for ($nation_id=1; $nation_id<=16; $nation_id++) {
							if ($strategy_user['voting_strategy'] == "by_nation") $nation_pct_sum += $strategy_user['nation_pct_'.$nation_id];
							
							$pct_of_votes = 100*$ranked_stats[$nation_id2rank[$nation_id]]['voting_sum']/$score_sum;
							if ($pct_of_votes >= $strategy_user['min_votesum_pct'] && $pct_of_votes <= $strategy_user['max_votesum_pct']) {}
							else {
								$skipped_nations[$nation_id] = TRUE;
								if ($strategy_user['voting_strategy'] == "by_nation") $skipped_pct_points += $strategy_user['nation_pct_'.$nation_id];
								else if (in_array($nation_id2rank[$nation_id], $by_rank_ranks)) $num_nations_skipped++;
							}
						}
						
						if ($strategy_user['voting_strategy'] == "by_rank") {
							$divide_into = count($by_rank_ranks)-$num_nations_skipped;
							
							$coins_each = floor(($free_balance-$strategy_user['transaction_fee'])/$divide_into);
							
							$log_text .= "Dividing by rank among ".$divide_into." nations for ".$strategy_user['username']."<br/>";
							
							$nation_ids = array();
							$amounts = array();
							
							for ($rank=1; $rank<=16; $rank++) {
								if (in_array($rank, $by_rank_ranks) && !$skipped_nations[$ranked_stats[$rank-1]['nation_id']]) {
									$log_text .= "Vote ".round($coins_each/pow(10,8), 3)." EMP for ".$ranked_stats[$rank-1]['name'].", ranked ".$rank."<br/>";
									
									$nation_ids[count($nation_ids)] = $ranked_stats[$rank-1]['nation_id'];
									$amounts[count($amounts)] = $coins_each;
								}
							}
							$transaction_id = new_webwallet_multi_transaction($game, $nation_ids, $amounts, $strategy_user['user_id'], $strategy_user['user_id'], false, 'transaction', false, false, false, $strategy_user['transaction_fee']);
							
							if ($transaction_id) $log_text .= "Added transaction $transaction_id<br/>\n";
							else $log_text .= "Failed to add transaction.<br/>\n";
						}
						else { // by_nation
							$log_text .= "Dividing by nation for ".$strategy_user['username']." (".(($free_balance-$strategy_user['transaction_fee'])/pow(10,8))." EMP)<br/>\n";
							
							$mult_factor = 1;
							if ($skipped_pct_points > 0) {
								$mult_factor = floor(pow(10,6)*$nation_pct_sum/($nation_pct_sum-$skipped_pct_points))/pow(10,6);
							}
							
							if ($nation_pct_sum == 100) {
								$nation_ids = array();
								$amounts = array();
								
								for ($nation_id=1; $nation_id<=16; $nation_id++) {
									if (!$skipped_nations[$nation_id] && $strategy_user['nation_pct_'.$nation_id] > 0) {
										$effective_frac = floor(pow(10,4)*$strategy_user['nation_pct_'.$nation_id]*$mult_factor)/pow(10,6);
										$coin_amount = floor($effective_frac*($free_balance-$strategy_user['transaction_fee']));
										
										$log_text .= "Vote ".$strategy_user['nation_pct_'.$nation_id]."% (".round($coin_amount/pow(10,8), 3)." EMP) for ".$ranked_stats[$nation_id2rank[$nation_id]]['name']."<br/>";
										
										$nation_ids[count($nation_ids)] = $nation_id;
										$amounts[count($amounts)] = $coin_amount;
									}
								}
								$transaction_id = new_webwallet_multi_transaction($game, $nation_ids, $amounts, $strategy_user['user_id'], $strategy_user['user_id'], false, 'transaction', false, false, false, $strategy_user['transaction_fee']);
								
								if ($transaction_id) $log_text .= "Added transaction $transaction_id<br/>\n";
								else $log_text .= "Failed to add transaction.<br/>\n";
							}
						}
					}
				}
			}
		}
	}
	return $log_text;
}

function ensure_game_nations($game_id) {
	$qq = "SELECT * FROM nations;";
	$rr = run_query($qq);
	while ($nation = mysql_fetch_array($rr)) {
		$qqq = "INSERT INTO game_nations SET game_id='".$game_id."', nation_id='".$nation['nation_id']."';";
		$rrr = run_query($qqq);
	}
}

function delete_reset_game($delete_or_reset, $game_id) {
	$q = "SELECT * FROM games WHERE game_id='".$game_id."';";
	$r = run_query($q);
	$game = mysql_fetch_array($r);
	
	$q = "DELETE FROM webwallet_transactions WHERE game_id='".$game_id."';";
	$r = run_query($q);
	
	$q = "DELETE FROM transaction_IOs WHERE game_id='".$game_id."';";
	$r = run_query($q);
	
	$q = "DELETE FROM blocks WHERE game_id='".$game_id."';";
	$r = run_query($q);
	
	$q = "DELETE FROM cached_rounds WHERE game_id='".$game_id."';";
	$r = run_query($q);
	
	$q = "DELETE FROM game_nations WHERE game_id='".$game_id."';";
	$r = run_query($q);
	
	if ($game['game_type'] == "simulation") {
		$q = "DELETE FROM addresses WHERE game_id='".$game_id."';";
		$r = run_query($q);
	}
	
	if ($delete_or_reset == "reset") {
		ensure_game_nations($game_id);
		
		$q = "SELECT * FROM user_games ug JOIN games g ON ug.game_id=g.game_id WHERE ug.game_id='".$game_id."';";
		$r = run_query($q);
		
		$giveaway_block_id = last_block_id($game_id);
		if (!$giveaway_block_id) $giveaway_block_id = 0;
		
		while ($user_game = mysql_fetch_array($r)) {
			generate_user_addresses($user_game);
		}
	}
	else {
		$q = "DELETE g.*, ug.* FROM games g, user_games ug WHERE g.game_id=".$game_id." AND ug.game_id=g.game_id;";
		$r = run_query($q);
		
		$q = "DELETE FROM user_strategies WHERE game_id='".$game_id."';";
		$r = run_query($q);
	}
	return true;
}

function block_id_to_round_index(&$game, $mining_block_id) {
	return (($mining_block_id-1)%$game['round_length'])+1;
}

function render_transaction($transaction, $selected_address_id, $firstcell_text) {
	$html = "";
	$html .= '<div class="row bordered_row"><div class="col-md-6">';
	$html .= "<a href=\"/explorer/transactions/".$transaction['tx_hash']."\" class=\"display_address\" style=\"display: inline-block; max-width: 100%; overflow: hidden;\">TX:&nbsp;".$transaction['tx_hash']."</a><br/>";
	if ($firstcell_text != "") $html .= $firstcell_text."<br/>\n";
	
	if ($transaction['transaction_desc'] == "votebase") {
		$html .= "Voting Payout&nbsp;&nbsp;".round($transaction['amount']/pow(10,8), 2)." coins";
	}
	else if ($transaction['transaction_desc'] == "coinbase") {
		$html .= "Miner found a block.";
	}
	else {
		$qq = "SELECT * FROM transaction_IOs i JOIN addresses a ON i.address_id=a.address_id LEFT JOIN nations n ON a.nation_id=n.nation_id WHERE i.spend_transaction_id='".$transaction['transaction_id']."' ORDER BY i.amount DESC;";
		$rr = run_query($qq);
		$input_sum = 0;
		while ($input = mysql_fetch_array($rr)) {
			$html .= number_format($input['amount']/pow(10,8), 2)."&nbsp;coins&nbsp; ";
			$html .= "<a class=\"display_address\" style=\"";
			if ($input['address_id'] == $selected_address_id) $html .= " font-weight: bold; color: #000;";
			$html .= "\" href=\"/explorer/addresses/".$input['address']."\">".$input['address']."</a>";
			if ($input['name'] != "") $html .= "&nbsp;&nbsp;(".$input['name'].")";
			$html .= "<br/>\n";
			$input_sum += $input['amount'];
		}
	}
	$html .= '</div><div class="col-md-6">';
	$qq = "SELECT i.*, n.*, a.*, p.amount AS payout_amount FROM transaction_IOs i LEFT JOIN transaction_IOs p ON i.payout_io_id=p.io_id, addresses a LEFT JOIN nations n ON a.nation_id=n.nation_id WHERE i.create_transaction_id='".$transaction['transaction_id']."' AND i.address_id=a.address_id ORDER BY i.out_index ASC;";
	$rr = run_query($qq);
	$output_sum = 0;
	while ($output = mysql_fetch_array($rr)) {
		$html .= "<a class=\"display_address\" style=\"";
		if ($output['address_id'] == $selected_address_id) $html .= " font-weight: bold; color: #000;";
		$html .= "\" href=\"/explorer/addresses/".$output['address']."\">".$output['address']."</a>&nbsp; ";
		$html .= number_format($output['amount']/pow(10,8), 2)."&nbsp;coins";
		if ($output['name'] != "") $html .= "&nbsp;&nbsp;".$output['name'];
		if ($output['payout_amount'] > 0) $html .= "&nbsp;&nbsp;<font class=\"greentext\">+".round($output['payout_amount']/pow(10,8), 2)."</font>";
		$html .= "<br/>\n";
		$output_sum += $output['amount'];
	}
	$transaction_fee = $input_sum-$output_sum;
	if ($transaction['transaction_desc'] != "coinbase" && $transaction['transaction_desc'] != "votebase") $html .= "Transaction fee: ".format_bignum($transaction_fee/pow(10,8))." coins";
	$html .= '</div></div>'."\n";
	
	return $html;
}
function select_input_buttons($user_id, &$game) {
	$js = "mature_ios.length = 0;\n";
	$html = "";
	$input_buttons_html = "";
	
	$last_block_id = last_block_id($game['game_id']);
	
	$output_q = "SELECT * FROM transaction_IOs i JOIN addresses a ON i.address_id=a.address_id WHERE i.spend_status='unspent' AND i.spend_transaction_id IS NULL AND a.user_id='".$user_id."' AND i.game_id='".$game['game_id']."' ORDER BY i.io_id ASC;";
	$output_r = run_query($output_q);
	
	$utxos = array();
	$viewable_count = 0;
	
	while ($utxo = mysql_fetch_array($output_r)) {
		if (intval($utxo['create_block_id']) > 0) {} else $utxo['create_block_id'] = 0;
		
		$utxos[count($utxos)] = $utxo;
		$input_buttons_html .= '<div ';
		
		if ($utxo['create_block_id'] > $last_block_id-$game['maturity'] && $utxo['instantly_mature'] == 0) {
			$utxo['initially_hidden'] = true;
			$input_buttons_html .= 'style="display: none;" ';
		}
		else $viewable_count++;
		
		$input_buttons_html .= 'id="select_utxo_'.$utxo['io_id'].'" class="select_utxo" onclick="add_utxo_to_vote(\''.$utxo['io_id'].'\', '.$utxo['amount'].', '.$utxo['create_block_id'].');">';
		$input_buttons_html .= '</div>'."\n";
		
		$js .= "mature_ios.push(new mature_io(mature_ios.length, ".$utxo['io_id'].", ".$utxo['amount'].", ".$utxo['create_block_id']."));\n";
	}
	$js .= "refresh_mature_io_btns();\n";
	
	$html .= "<div id=\"select_input_buttons_msg\">";
	if ($viewable_count > 0) {
		$html .= "To compose a voting transaction, please add money with the boxes below and then select the nations that you want to vote for.";
	}
	else {
		$html .= '<font class="redtext">You don\'t have any coins available to vote right now.</font>';
	}
	$html .= "</div>\n";
	
	$html .= $input_buttons_html;

	$html .= "<script type=\"text/javascript\">".$js."</script>\n";
	
	return $html;
}
function mature_io_ids_csv($user_id, &$game) {
	if ($user_id > 0 && $game) {
		$ids_csv = "";
		$io_q = "SELECT i.io_id FROM transaction_IOs i, addresses a WHERE i.address_id=a.address_id AND i.spend_status='unspent' AND a.user_id='".$user_id."' AND i.game_id='".$game['game_id']."' AND (i.create_block_id <= ".(last_block_id($game['game_id'])-$game['maturity'])." OR i.instantly_mature = 1) ORDER BY i.io_id ASC;";
		$io_r = run_query($io_q);
		while ($io = mysql_fetch_row($io_r)) {
			$ids_csv .= $io[0].",";
		}
		if ($ids_csv != "") $ids_csv = substr($ids_csv, 0, strlen($ids_csv)-1);
		return $ids_csv;
	}
	else return "";
}
function bet_round_range(&$game) {
	$last_block_id = last_block_id($game['game_id']);
	$mining_block_within_round = block_id_to_round_index($game, $last_block_id+1);
	$current_round = block_to_round($game, $last_block_id+1);
	
	if ($mining_block_within_round <= 5) $start_round_id = $current_round;
	else $start_round_id = $current_round+1;
	$stop_round_id = $start_round_id+99;
	
	return array($start_round_id, $stop_round_id);
}
function round_to_last_betting_block(&$game, $round_id) {
	return ($round_id-1)*$game['round_length']+5;
}
function select_bet_round(&$game, $current_round) {
	$html = '<select id="bet_round" class="form-control" required="required" onchange="bet_round_changed();">';
	$html .= '<option value="">-- Please Select --</option>'."\n";
	$bet_round_range = bet_round_range($game);
	for ($round_id=$bet_round_range[0]; $round_id<=$bet_round_range[1]; $round_id++) {
		$html .= "<option value=\"".$round_id."\">Round #".$round_id;
		if ($round_id == $current_round) $html .= " (Current round)";
		else {
			$seconds_until = floor(($round_id-$current_round)*$game['round_length']*$game['seconds_per_block']);
			$minutes_until = floor($seconds_until/60);
			$hours_until = floor($seconds_until/3600);
			$html .= " (";
			if ($hours_until > 1) $html .= "+".$hours_until." hours";
			else if ($minutes_until > 1) $html .= "+".$minutes_until." minutes";
			else $html .= "+".$seconds_until." seconds";
			$html .= ")";
		}
		$html .= "</option>\n";
	}
	$html .= '</select>'."\n";
	return $html;
}

function burn_address_text(&$game, $round_id, $winner) {
	$addr_text = "";
	if ($winner) {
		$q = "SELECT * FROM nations WHERE nation_id='".$winner."';";
		$r = run_query($q);
		if (mysql_numrows($r) == 1) {
			$nation = mysql_fetch_array($r);
			$addr_text .= strtolower($nation['name'])."_wins";
		}
		else return false;
	}
	else {
		$addr_text .= "no_winner";
	}
	$addr_text .= "_round_".$round_id;
	
	return $addr_text;
}

function get_bet_burn_address(&$game, $round_id, $nation_id) {
	if ($game['losable_bets_enabled'] == 1) {
		$burn_address_text = burn_address_text($game, $round_id, $nation_id);
		
		$q = "SELECT * FROM addresses WHERE game_id='".$game['game_id']."' AND address='".$burn_address_text."';";
		$r = run_query($q);
		
		if (mysql_numrows($r) > 0) {
			$burn_address = mysql_fetch_array($r);
		}
		else {
			$q = "INSERT INTO addresses SET game_id='".$game['game_id']."', address='".$burn_address_text."', bet_round_id='".$round_id."'";
			$q .= ", bet_nation_id='".$nation_id."'";
			$q .= ";";
			$r = run_query($q);
			$burn_address_id = mysql_insert_id();
			
			$q = "SELECT * FROM addresses WHERE address_id='".$burn_address_id."';";
			$r = run_query($q);
			$burn_address = mysql_fetch_array($r);
		}
		return $burn_address;
	}
	else return false;
}

function bet_transaction_payback_address($transaction_id) {
	$q = "SELECT * FROM transaction_IOs i, webwallet_transactions t, addresses a WHERE t.transaction_id='".$transaction_id."' AND i.spend_transaction_id=t.transaction_id AND i.address_id=a.address_id ORDER BY a.address ASC LIMIT 1;";
	$r = run_query($q);
	if (mysql_numrows($r) == 1) {
		return mysql_fetch_array($r);
	}
	else return false;
}

function rounds_complete_html(&$game, $max_round_id, $limit) {
	$html = "";
	
	$last_block_id = last_block_id($game['game_id']);
	$current_round = block_to_round($game, $last_block_id+1);
	if ($max_round_id == $current_round) {
		$html .= "<div class=\"row bordered_row\">";
		$html .= "<div class=\"col-sm-2\"><a href=\"/explorer/rounds/".$max_round_id."\">Round #".$max_round_id."</a></div>";
		$html .= "<div class=\"col-sm-7\">Not yet decided";
		$html .= "</div>";
		$html .= "<div class=\"col-sm-3\">".format_bignum(0)." votes cast</div>";
		$html .= "</div>\n";
	}
	
	$q = "SELECT * FROM cached_rounds r LEFT JOIN nations n ON r.winning_nation_id=n.nation_id WHERE r.game_id='".$game['game_id']."' AND r.round_id <= ".$max_round_id." ORDER BY r.round_id DESC LIMIT ".$limit.";";
	$r = run_query($q);
	
	$last_round_shown = 0;
	while ($cached_round = mysql_fetch_array($r)) {
		$html .= "<div class=\"row bordered_row\">";
		$html .= "<div class=\"col-sm-2\"><a href=\"/explorer/rounds/".$cached_round['round_id']."\">Round #".$cached_round['round_id']."</a></div>";
		$html .= "<div class=\"col-sm-7\">";
		if ($cached_round['winning_nation_id'] > 0) {
			$html .= $cached_round['name']." wins with ".format_bignum($cached_round['winning_score']/pow(10,8))." votes (".round(100*$cached_round['winning_score']/$cached_round['score_sum'], 2)."%)";
		}
		else $html .= "No winner";
		$html .= "</div>";
		$html .= "<div class=\"col-sm-3\">".format_bignum($cached_round['score_sum']/pow(10,8))." votes cast</div>";
		$html .= "</div>\n";
		$last_round_shown = $cached_round['round_id'];
	}
	
	$returnvals[0] = $last_round_shown;
	$returnvals[1] = $html;
	
	return $returnvals;
}

function addr_text_to_nation_id($addr_text) {
	$nation_id = false;
	if (strtolower($addr_text[0].$addr_text[1]) == "ee") {
		$q = "SELECT * FROM nations WHERE address_character='".strtolower($addr_text[2])."';";
		$r = run_query($q);
		if (mysql_numrows($r) > 0) {
			$nation = mysql_fetch_array($r);
			$nation_id = $nation['nation_id'];
		}
	}
	return $nation_id;
}

function my_bets(&$game, $user) {
	$html = "";
	$q = "SELECT * FROM webwallet_transactions WHERE transaction_desc='bet' AND game_id='".$game['game_id']."' AND from_user_id='".$user['user_id']."' GROUP BY bet_round_id ORDER BY bet_round_id ASC;";
	$r = run_query($q);
	
	if (mysql_numrows($r) > 0) {
		$last_block_id = last_block_id($user['game_id']);
		$current_round = block_to_round($game, $last_block_id+1);
		
		$html .= "<h2>You've placed bets on ".mysql_numrows($r)." round";
		if (mysql_numrows($r) != 1) $html .= "s";
		$html .= ".</h2>\n";
		$html .= "<div style=\"border: 1px solid #bbb; border-top: 0px;\">";
		while ($bet_round = mysql_fetch_array($r)) {
			$html .= "<div class=\"row bordered_row\" style=\"margin: 0px; padding: 6px;\">";
			$disp_html = "";
			$qq = "SELECT a.*, n.*, SUM(i.amount) FROM webwallet_transactions t JOIN transaction_IOs i ON i.create_transaction_id=t.transaction_id JOIN addresses a ON i.address_id=a.address_id LEFT JOIN nations n ON a.bet_nation_id=n.nation_id WHERE t.game_id='".$game['game_id']."' AND t.from_user_id='".$user['user_id']."' AND t.bet_round_id='".$bet_round['bet_round_id']."' AND a.bet_round_id > 0 GROUP BY a.address_id ORDER BY SUM(i.amount) DESC;";
			$rr = run_query($qq);
			$coins_bet_for_round = 0;
			while ($nation_bet = mysql_fetch_array($rr)) {
				if ($nation_bet['name'] == "") $nation_bet['name'] = "No Winner";
				$coins_bet_for_round += $nation_bet['SUM(i.amount)'];
				$disp_html .= "<div class=\"row\">";
				$disp_html .= "<div class=\"col-md-5\">".number_format($nation_bet['SUM(i.amount)']/pow(10,8), 2)." coins towards ".$nation_bet['name']."</div>";
				$disp_html .= "<div class=\"col-md-5\"><a href=\"/explorer/addresses/".$nation_bet['address']."\">".$nation_bet['address']."</a></div>";
				$disp_html .= "</div>\n";
			}
			if ($bet_round['bet_round_id'] >= $current_round) {
				$html .= "You made bets totalling ".number_format($coins_bet_for_round/pow(10,8), 2)." coins on round ".$bet_round['bet_round_id'].".";
			}
			else {
				$qq = "SELECT SUM(i.amount) FROM webwallet_transactions t JOIN transaction_IOs i ON t.transaction_id=i.create_transaction_id JOIN addresses a ON i.address_id=a.address_id WHERE t.block_id='".($bet_round['bet_round_id']*$game['round_length'])."' AND t.transaction_desc='betbase' AND a.user_id='".$user['user_id']."';";
				$rr = run_query($qq);
				$amount_won = mysql_fetch_row($rr);
				$amount_won = $amount_won[0];
				if ($amount_won > 0) {
					$html .= "You bet ".number_format($coins_bet_for_round/pow(10,8), 2)." coins and won ".number_format($amount_won/pow(10,8), 2)." back for a ";
					if (round(($amount_won-$coins_bet_for_round)/pow(10,8), 2) >= 0) $html .= "profit of <font class=\"greentext\">+".number_format(round(($amount_won-$coins_bet_for_round)/pow(10,8), 2), 2)."</font> coins.";
					else $html .= "loss of <font class=\"redtext\">".number_format(($coins_bet_for_round-$amount_won)/pow(10,8), 2)."</font> coins.";
				}
			}
			$html .= "&nbsp;&nbsp; <a href=\"\" onclick=\"$('#my_bets_details_".$bet_round['bet_round_id']."').toggle('fast'); return false;\">Details</a><br/>\n";
			$html .= "<div id=\"my_bets_details_".$bet_round['bet_round_id']."\" style=\"display: none;\">".$disp_html."</div>\n";
			$html .= "</div>\n";
		}
		$html .= "</div>\n";
	}
	return $html;
}

function add_round_from_rpc(&$game, $round_id) {
	$q = "UPDATE game_nations SET coin_score=0, coin_block_score=0, losing_streak=losing_streak+1 WHERE game_id='".$game['game_id']."';";
	$r = run_query($q);
	
	$winning_nation_id = false;
	$q = "SELECT * FROM webwallet_transactions t JOIN transaction_IOs i ON i.create_transaction_id=t.transaction_id JOIN addresses a ON a.address_id=i.address_id WHERE t.game_id='".$game['game_id']."' AND t.block_id='".$round_id*$game['round_length']."' AND t.transaction_desc='votebase' AND i.out_index=1;";
	$r = run_query($q);
	if (mysql_numrows($r) == 1) {
		$votebase_transaction = mysql_fetch_array($r);
		$winning_nation_id = $votebase_transaction['nation_id'];
	}
	
	$q = "SELECT * FROM cached_rounds WHERE game_id='".$game['game_id']."' AND round_id='".$round_id."';";
	$r = run_query($q);
	if (mysql_numrows($r) > 0) {
		$existing_round = mysql_fetch_array($r);
		$update_insert = "update";
	}
	else $update_insert = "insert";
	
	if ($update_insert == "update") $q = "UPDATE cached_rounds SET ";
	else $q = "INSERT INTO cached_rounds SET game_id='".$game['game_id']."', round_id='".$round_id."', ";
	$q .= "payout_block_id='".($round_id*$game['round_length'])."'";
	if ($winning_nation_id) $q .= ", winning_nation_id='".$winning_nation_id."'";
	
	$rankings = round_voting_stats_all($game, $round_id);
	$score_sum = $rankings[0];
	$nation_id_to_rank = $rankings[3];
	$rankings = $rankings[2];
	
	for ($i=0; $i<count($rankings); $i++) {
		$q .= ", position_".($i+1)."=".$rankings[$i]['nation_id'];
	}
	
	$nation_scores = nation_score_in_round($game, $winning_nation_id, $round_id);
	$q .= ", winning_score='".$nation_scores['sum']."', score_sum='".$score_sum."', time_created='".time()."'";
	if ($update_insert == "update") $q .= " WHERE internal_round_id='".$existing_round['internal_round_id']."'";
	$q .= ";";
	$r = run_query($q);
}

function add_user_to_match($user_id, $match_id, $position, $check_existing) {
	$already_exists = false;
	if ($check_existing) {
		$q = "SELECT * FROM match_memberships WHERE match_id='".$match_id."' AND user_id='".$user_id."';";
		$r = run_query($q);
		if (mysql_numrows($r) > 0) $already_exists = true;
	}
	if ($position === false) {
		$q = "SELECT * FROM match_memberships WHERE match_id='".$match_id."' ORDER BY player_position DESC LIMIT 1;";
		$r = run_query($q);
		if (mysql_numrows($r) > 0) {
			$previous_player = mysql_fetch_row($r);
			$position = $previous_player['player_position']+1;
		}
		else $position = 0;
	}
	if (!$already_exists) {
		$q = "INSERT INTO match_memberships SET match_id='".$match_id."', user_id='".$user_id."', player_position=".$position.", time_joined='".time()."';";
		$r = run_query($q);
		$q = "UPDATE matches SET num_joined=num_joined+1 WHERE match_id='".$match_id."';";
		$r = run_query($q);
		
		$q = "SELECT * FROM match_types t JOIN matches m ON t.match_type_id=m.match_type_id WHERE m.match_id='".$match_id."';";
		$r = run_query($q);
		$match = mysql_fetch_array($r);
		if ($match['num_players'] == $match['num_joined']) $match_status = "running";
		else $match_status = "pending";
		set_match_status($match, $match_status);
	}
}

function user_match_membership($user_id, $match_id) {
	$q = "SELECT * FROM match_memberships WHERE user_id='".$user_id."' AND match_id='".$match_id."';";
	$r = run_query($q);
	if (mysql_numrows($r) > 0) {
		return mysql_fetch_array($r);
	}
	else return false;
}

function start_match_move(& $match, $membership_id, $type, $amount) {
	$initial_round_number = $match['current_round_number'];
	
	$qqq = "INSERT INTO match_moves SET membership_id='".$membership_id."', move_type='".$type."', amount='".$amount."', round_number='".$match['current_round_number']."', move_number='".($match['last_move_number']+1)."', time_created='".time()."';";
	$rrr = run_query($qqq);
	$move_id = mysql_insert_id();
	
	$qqq = "UPDATE matches SET last_move_number=last_move_number+1 WHERE match_id='".$match['match_id']."';";
	$rrr = run_query($qqq);
	
	if ($match['turn_based'] == 0) {
		$qqq = "UPDATE matches SET current_round_number=FLOOR(last_move_number/".$match['num_players'].") WHERE match_id='".$match['match_id']."';";
		$rrr = run_query($qqq);
	}
	
	$qqq = "SELECT * FROM match_types t JOIN matches m ON t.match_type_id=m.match_type_id AND m.match_id='".$match['match_id']."';";
	$rrr = run_query($qqq);
	$match = mysql_fetch_array($rrr);
	
	if ($match['current_round_number'] != $initial_round_number) {
		$qqq = "SELECT * FROM match_moves mv JOIN match_memberships mem ON mv.membership_id=mem.membership_id WHERE mem.match_id='".$match['match_id']."' AND mv.round_number='".$initial_round_number."';";
		$rrr = run_query($qqq);
		while ($match_move = mysql_fetch_array($rrr)) {
			finalize_match_move($match_move['move_id']);
		}
		finish_match_round($match, $initial_round_number);
	}
	
	return $move_id;
}

function finalize_match_move($move_id) {
	$q = "SELECT * FROM match_moves WHERE move_id='".$move_id."';";
	$r = run_query($q);
	$match_move = mysql_fetch_array($r);
	
	$qqq = "SELECT * FROM match_types t JOIN matches m ON t.match_type_id=m.match_type_id AND m.match_id='".$match_move['match_id']."';";
	$rrr = run_query($qqq);
	$match = mysql_fetch_array($rrr);
	
	if ($type == "deposit") {
		$qqq = "INSERT INTO match_IOs SET membership_id='".$match_move['membership_id']."', match_id='".$match['match_id']."', create_move_id='".$move_id."', amount='".$match_move['amount']."';";
		$rrr = run_query($qqq);
	}
	else if ($type == "burn") {
		$qqq = "SELECT * FROM match_IOs WHERE membership_id='".$match_move['membership_id']."' AND spend_status='unspent' ORDER BY amount DESC;";
		$rrr = run_query($qqq);
		
		$input_sum = 0;
		
		while ($match_io = mysql_fetch_array($rrr)) {
			if ($input_sum < $match_move['amount']) {
				$q = "UPDATE match_IOs SET spend_status='spent', spend_move_id='".$move_id."' WHERE io_id='".$match_io['io_id']."';";
				$r = run_query($q);
				$input_sum += $match_io['amount'];
			}
		}
		
		$overshoot_amount = $input_sum - $amount;
		
		if ($overshoot_amount > 0) {
			$q = "INSERT INTO match_IOs SET membership_id='".$match_move['membership_id']."', match_id='".$match['match_id']."', create_move_id='".$move_id."', amount='".$overshoot_amount."';";
			$r = run_query($q);
			$output_id = mysql_insert_id();	
		}
		
		$qqq = "INSERT INTO match_IOs SET spend_status='spent', membership_id='".$match_move['membership_id']."', match_id='".$match['match_id']."', create_move_id='".$move_id."', amount='".$match_move['amount']."';";
		$rrr = run_query($qqq);
	}
	
	return $move_id;
}

function get_match_round($match_id, $round_number) {
	$q = "SELECT * FROM match_rounds WHERE match_id='".$match_id."' AND round_number='".$round_number."';";
	$r = run_query($q);
	
	if (mysql_numrows($r) > 0) {
		$match_round = mysql_fetch_array($r);
	}
	else {
		$q = "INSERT INTO match_rounds SET match_id='".$match_id."', round_number='".$round_number."';";
		$r = run_query($q);
		$match_round_id = mysql_insert_id();
		
		$q = "SELECT * FROM match_rounds WHERE match_round_id='".$match_round_id."';";
		$r = run_query($q);
		$match_round = mysql_fetch_array($r);
	}
	
	return $match_round;
}

function finish_match_round(& $match, $round_number) {
	$q = "SELECT * FROM match_moves mv JOIN match_memberships mem ON mv.membership_id=mem.membership_id JOIN users u ON mem.user_id=u.user_id WHERE mem.match_id='".$match['match_id']."' AND mv.round_number=".$round_number." AND mv.move_type='burn' ORDER BY mv.amount DESC;";
	$r = run_query($q);
	
	$winner = false;
	$num_tied_for_first = 0;
	$best_amount = false;
	
	while ($move = mysql_fetch_array($r)) {
		if (!$winner) {
			$winner = $move;
			$best_amount = $move['amount'];
			$num_tied_for_first++;
		}
		else {
			if ($move['amount'] == $best_amount) $num_tied_for_first++;
		}
	}
	
	$match_round = get_match_round($match['match_id'], $round_number);
	
	if ($num_tied_for_first == 1) {
		$q = "UPDATE match_rounds SET status='won', winning_membership_id='".$winner['membership_id']."' WHERE match_round_id='".$match_round['match_round_id']."';";
		$r = run_query($q);
		add_match_message($match['match_id'], "Player #".($winner['player_position']+1)." won round #".$round_number." with ".$winner['amount']/pow(10,8)." coins.", false, false, false);
	}
	else {
		$q = "UPDATE match_rounds SET status='tied' WHERE match_round_id='".$match_round['match_round_id']."';";
		$r = run_query($q);
		add_match_message($match['match_id'], $num_tied_for_first." players tied for round #".$round_number, false, false, false);
	}
}

function match_account_value($membership_id) {
	$q = "SELECT SUM(amount) FROM match_IOs WHERE spend_status='unspent' AND membership_id='".$membership_id."';";
	$r = run_query($q);
	$sum = mysql_fetch_row($r);
	return $sum[0];
}

function match_immature_balance($membership_id) {
	return 0;
}

function match_mature_balance($membership_id) {
	$account_value = match_account_value($membership_id);
	$immature_balance = match_immature_balance($membership_id);
	
	return ($account_value - $immature_balance);
}

function initialize_match(& $match) {
	if ($match['turn_based'] == 1) $firstplayer_position = rand(0, $match['num_players']-1);
	else $firstplayer_position = 0;
	
	$qq = "SELECT * FROM match_memberships mm JOIN users u ON mm.user_id=u.user_id WHERE mm.match_id='".$match['match_id']."' ORDER BY mm.player_position ASC;";
	$rr = run_query($qq);
	while ($membership = mysql_fetch_array($rr)) {
		add_match_message($match['match_id'], "Anonymous joined the game at ".date("g:ia", $membership['time_joined'])." as player #".($membership['player_position']+1), false, false, $membership['user_id']);
		add_match_message($match['match_id'], "You joined the game at ".date("g:ia", $membership['time_joined'])." as player #".($membership['player_position']+1), false, $membership['user_id'], false);
		
		$move_id = start_match_move($match, $membership['membership_id'], 'deposit', $match['initial_coins_per_player']);
		finalize_match_move($move_id);
	}
	$deposit_msg = "The dealer hands out ".number_format($match['initial_coins_per_player']/pow(10,8))." coins to each player, the game begins.";
	add_match_message($match['match_id'], $deposit_msg, false, false, false);
	
	if ($match['turn_based'] == 1) {
		if ($match['num_players'] == 2) {
			if ($firstplayer_position == 0) $heads_tails = "heads";
			else $heads_tails = "tails";
			add_match_message($match['match_id'], "Player 1 calls heads.", false, false, false);
			add_match_message($match['match_id'], "The dealer flips a coin..", false, false, false);
			add_match_message($match['match_id'], "The coin comes up $heads_tails, player ".($firstplayer_position+1)." goes first.", false, false, false);
		}
		else {
			add_match_message($match['match_id'], "The dealer rolls a ".$match['num_players']."-sided die..", false, false, false);
			add_match_message($match['match_id'], "The dice comes up ".($firstplayer_position+1).", player ".($firstplayer_position+1)." goes first.", false, false, false);
		}
	}
	
	return $firstplayer_position;
}

function set_match_status(& $match, $status) {
	$q = "UPDATE matches SET status='".$status."'";
	if ($match['firstplayer_position'] == -1 && $status == "running") {
		$firstplayer_position = initialize_match($match);
		$q .= ", firstplayer_position=".$firstplayer_position;
	}
	$q .= " WHERE match_id='".$match['match_id']."';";
	$r = run_query($q);
}

function add_match_message($match_id, $message, $from_user_id, $to_user_id, $hide_user_id) {
	$q = "INSERT INTO match_messages SET match_id='".$match_id."', message='".mysql_real_escape_string($message)."'";
	if ($from_user_id) $q .= ", from_user_id='".$from_user_id."'";
	if ($to_user_id) $q .= ", to_user_id='".$to_user_id."'";
	if ($hide_user_id) $q .= ", hide_user_id='".$hide_user_id."'";
	$q .= ", time_created='".time()."';";
	$r = run_query($q);
}

function show_match_messages(& $match, $user_id, $last_message_id) {
	$q = "SELECT * FROM match_messages WHERE match_id='".$match['match_id']."' AND (to_user_id='".$user_id."' OR to_user_id IS NULL) AND (hide_user_id != '".$user_id."' OR hide_user_id IS NULL)";
	if ($last_message_id) $q .= " AND message_id > ".$last_message_id;
	$q .= " ORDER BY time_created ASC;";
	$r = run_query($q);
	$html = "";
	while ($message = mysql_fetch_array($r)) {
		$html .= $message['message']."<br/>\n";
	}
	return $html;
}

function match_current_player(& $match) {
	$player_position = ($match['firstplayer_position']+$match['last_move_number'])%$match['num_players'];
	$q = "SELECT * FROM match_memberships mm JOIN users u ON mm.user_id=u.user_id WHERE mm.player_position='".$player_position."' AND mm.match_id='".$match['match_id']."';";
	$r = run_query($q);
	$player = mysql_fetch_array($r);
	return $player;
}

function last_match_message($match_id) {
	$q = "SELECT message_id FROM match_messages WHERE match_id='".$match_id."' ORDER BY message_id DESC LIMIT 1;";
	$r = run_query($q);
	if (mysql_numrows($r) > 0) {
		$last_message = mysql_fetch_array($r);
		return $last_message['message_id'];
	}
	else return 0;
}
function match_body(& $match, & $my_membership, $thisuser) {
	$html = "";
	
	if ($match['status'] == "pending") {
		$html .= "Great, this game is ready to begin!<br/>";
		$html .= '<button class="btn btn-success" onclick="start_match('.$match['match_id'].');">Begin the game</button>';
	}
	else if ($match['status'] == "running") {
		$q = "SELECT * FROM match_memberships mem JOIN users u ON mem.user_id=u.user_id WHERE mem.match_id='".$match['match_id']."' ORDER BY player_position ASC;";
		$r = run_query($q);
		while ($player = mysql_fetch_array($r)) {
			$qq = "SELECT COUNT(*) FROM match_rounds WHERE match_id='".$match['match_id']."' AND winning_membership_id='".$player['membership_id']."';";
			$rr = run_query($qq);
			$player_wins = mysql_fetch_row($rr);
			$player_wins = $player_wins[0];
			
			$html .= "<div class=\"row\"";
			if ($thisuser['user_id'] == $player['user_id']) $html .= " style=\"font-weight: bold;\"";
			$html .= "><div class=\"col-sm-8\">";
			if ($thisuser['user_id'] == $player['user_id']) $html .= "You have: ";
			else $html .= "Player #".($player['player_position']+1).": ";
			$html .= $player_wins." win";
			if ($player_wins != 1) $html .= "s";
			$html .= ", ".match_mature_balance($player['membership_id'])/pow(10,8)." coins left";
			$html .= "</div><div class=\"col-sm-4\">";
			$qq = "SELECT * FROM match_moves WHERE membership_id='".$player['membership_id']."' AND round_number='".$match['current_round_number']."';";
			$rr = run_query($qq);
			if (mysql_numrows($rr) > 0) $html .= "Moved submitted";
			else $html .= "Awaiting move...";
			$html .= "</div></div>\n";
		}
		
		$html .= "<br/>You're currently on round ".$match['current_round_number']." of ".$match['num_rounds']."<br/>\n";
		
		$q = "SELECT * FROM match_moves WHERE membership_id='".$my_membership['membership_id']."' AND round_number='".$match['current_round_number']."';";
		$r = run_query($q);
		if (mysql_numrows($r) > 0) {
			$my_move = mysql_fetch_array($r);
			$html .= "You put <font class=\"greentext\">".$my_move['amount']/pow(10,8)." coins</font> down on this round.<br/>\n";
			$html .= "Waiting on your opponent...";
		}
		else {
			$html .= 'Please enter an amount or use the sliders below, then submit your move for this round.<br/><br/>';
			$html .= '
			<div class="row">
				<div class="col-sm-4">
					<input class="form-control" id="match_move_amount" type="tel" size="6" placeholder="0.00" />
				</div>
			</div>
			
			<div id="match_slider" class="noUiSlider"></div>
			
			<button id="match_slider_label" class="btn btn-primary" onclick="submit_move('.$match['match_id'].');">Submit Move</button>';
		}
	}
	else if ($match['status'] == "finished") {}
	
	return $html;
}

function round_result_html($match, $round_number, $thisuser) {
	$html = "";
	
	$match_round = get_match_round($match['match_id'], $round_number);
	
	if ($match_round['status'] == "won") {
		$qq = "SELECT * FROM match_memberships WHERE membership_id='".$match_round['winning_membership_id']."';";
		$rr = run_query($qq);
		$winner = mysql_fetch_array($rr);
		$html .= "<h1>";
		if ($winner['user_id'] == $thisuser['user_id']) $html .= "You won round #".$round_number."!";
		else $html .= "Player #".($winner['player_position']+1)." won round #".$round_number;
		$html .= "</h1>\n";
	}
	
	$q = "SELECT * FROM match_memberships mem JOIN users u ON mem.user_id=u.user_id JOIN match_moves mv ON mv.membership_id=mem.membership_id WHERE mem.match_id='".$match['match_id']."' AND mv.round_number='".$round_number."' ORDER BY mv.amount DESC;";
	$r = run_query($q);
	while ($player = mysql_fetch_array($r)) {
		$html .= "<div class=\"row\"><div class=\"col-sm-6\">";
		$html .= "Player #".($player['player_position']+1);
		$html .= "</div><div class=\"col-sm-6\" style=\"text-align: right;\">";
		$html .= $player['amount']/pow(10,8)." coins";
		$html .= "</div></div>\n";
	}
	
	return $html;
}

function create_or_fetch_address(&$game, $address, $check_existing, $rpc, $delete_nationless) {
	if ($check_existing) {
		$q = "SELECT * FROM addresses WHERE game_id='".$game['game_id']."' AND address='".$address."';";
		$r = run_query($q);
		if (mysql_numrows($r) > 0) {
			return mysql_fetch_array($r);
		}
	}
	$address_nation_id = addr_text_to_nation_id($address);
	
	if ($address_nation_id > 0 || !$delete_nationless) {
		$q = "INSERT INTO addresses SET game_id='".$game['game_id']."', address='".$address."', nation_id='".$address_nation_id."', time_created='".time()."';";
		$r = run_query($q);
		$output_address_id = mysql_insert_id();
		
		if ($rpc) {
			$validate_address = $rpc->validateaddress($address);
			
			if ($validate_address['ismine']) $is_mine = 1;
			else $is_mine = 0;
			
			$q = "UPDATE addresses SET is_mine=$is_mine WHERE address_id='".$output_address_id."';";
			$r = run_query($q);
		}
		
		$q = "SELECT * FROM addresses WHERE address_id='".$output_address_id."';";
		$r = run_query($q);
		return mysql_fetch_array($r);
	}
	else return false;
}

function walletnotify(&$game, $empirecoin_rpc, $tx_hash) {
	$html = "";
	
	$lastblock_id = last_block_id($game['game_id']);
	
	$getinfo = $empirecoin_rpc->getinfo();
	
	if ($getinfo['blocks'] > $lastblock_id) {
		$html .= "Need to add ".($getinfo['blocks']-$lastblock_id)."<br/>";
		$q = "SELECT * FROM blocks WHERE game_id='".$game['game_id']."' AND block_id='".$lastblock_id."';";
		$r = run_query($q);
		$lastblock = mysql_fetch_array($r);
		
		$lastblock_rpc = $empirecoin_rpc->getblock($lastblock['block_hash']);
		
		for ($block_i=1; $block_i<=$getinfo['blocks']-$lastblock_id; $block_i++) {
			$new_block_id = ($lastblock['block_id']+$block_i);
			$new_hash = $lastblock_rpc['nextblockhash'];
			$lastblock_rpc = $empirecoin_rpc->getblock($new_hash);
			$q = "INSERT INTO blocks SET game_id='".$game['game_id']."', block_hash='".$new_hash."', block_id='".$new_block_id."', time_created='".time()."';";
			$r = run_query($q);
			
			if ($block_i%$game['round_length'] == 0) {
				$q = "UPDATE game_nations SET coin_score=0, coin_block_score=0, losing_streak=losing_streak+1 WHERE game_id='".$game['game_id']."';";
				$r = run_query($q);
			}
			
			$html .= "looping through ".count($lastblock_rpc['tx'])." transactions in block #".$new_block_id."<br/>\n";
			
			for ($i=0; $i<count($lastblock_rpc['tx']); $i++) {
				$tx_hash = $lastblock_rpc['tx'][$i];
				
				$q = "SELECT * FROM webwallet_transactions WHERE game_id='".$game['game_id']."' AND tx_hash='".$tx_hash."';";
				$r = run_query($q);
				if (mysql_numrows($r) > 0) {
					$unconfirmed_tx = mysql_fetch_array($r);
					$q = "UPDATE webwallet_transactions SET block_id='".$new_block_id."' WHERE transaction_id='".$unconfirmed_tx['transaction_id']."';";
					$r = run_query($q);
					$q = "UPDATE transaction_IOs SET spend_status='unspent', create_block_id='".$new_block_id."' WHERE create_transaction_id='".$unconfirmed_tx['transaction_id']."';";
					$r = run_query($q);
					$q = "UPDATE transaction_IOs SET spend_status='spent', spend_block_id='".$new_block_id."' WHERE spend_transaction_id='".$unconfirmed_tx['transaction_id']."';";
					$r = run_query($q);
				}
				else {
					$raw_transaction = $empirecoin_rpc->getrawtransaction($tx_hash);
					$transaction_rpc = $empirecoin_rpc->decoderawtransaction($raw_transaction);
					
					$outputs = $transaction_rpc["vout"];
					$inputs = $transaction_rpc["vin"];
					
					if (count($inputs) == 1 && $inputs[0]['coinbase']) {
						$transaction_rpc->is_coinbase = true;
						$transaction_type = "coinbase";
						if (count($outputs) > 1) $transaction_type = "votebase";
					}
					else $transaction_type = "transaction";
					
					$output_sum = 0;
					for ($j=0; $j<count($outputs); $j++) {
						$output_sum += pow(10,8)*$outputs[$j]["value"];
					}
					
					$q = "INSERT INTO webwallet_transactions SET game_id='".$game['game_id']."', amount='".$output_sum."', transaction_desc='".$transaction_type."', tx_hash='".$tx_hash."', address_id=NULL, block_id='".$new_block_id."', time_created='".time()."';";
					$r = run_query($q);
					$db_transaction_id = mysql_insert_id();
					$html .= ". ";
					
					for ($j=0; $j<count($outputs); $j++) {
						$address = $outputs[$j]["scriptPubKey"]["addresses"][0];
						
						$output_address = create_or_fetch_address($game, $address, true, $empirecoin_rpc, false);
						
						$q = "INSERT INTO transaction_IOs SET spend_status='unspent', instantly_mature=0, game_id='".$game['game_id']."', out_index='".$j."', user_id='".$output_address['user_id']."', address_id='".$output_address['address_id']."'";
						if ($output_address['nation_id'] > 0) $q .= ", nation_id=".$output_address['nation_id'];
						$q .= ", create_transaction_id='".$db_transaction_id."', amount='".($outputs[$j]["value"]*pow(10,8))."', create_block_id='".$new_block_id."';";
						$r = run_query($q);
					}
				}
			}
			
			for ($i=0; $i<count($lastblock_rpc['tx']); $i++) {
				$tx_hash = $lastblock_rpc['tx'][$i];
				$q = "SELECT * FROM webwallet_transactions WHERE tx_hash='".$tx_hash."';";
				$r = run_query($q);
				$transaction = mysql_fetch_array($r);
				
				$raw_transaction = $empirecoin_rpc->getrawtransaction($tx_hash);
				$transaction_rpc = $empirecoin_rpc->decoderawtransaction($raw_transaction);
				
				$outputs = $transaction_rpc["vout"];
				$inputs = $transaction_rpc["vin"];
				
				$transaction_error = false;
				
				$output_sum = 0;
				for ($j=0; $j<count($outputs); $j++) {
					$output_sum += pow(10,8)*$outputs[$j]["value"];
				}
				
				$spend_io_ids = array();
				$input_sum = 0;
				
				if ($transaction['transaction_desc'] == "transaction") {
					for ($j=0; $j<count($inputs); $j++) {
						$q = "SELECT * FROM webwallet_transactions t JOIN transaction_IOs i ON t.transaction_id=i.create_transaction_id WHERE t.game_id='".$game['game_id']."' AND i.spend_status='unspent' AND t.tx_hash='".$inputs[$j]["txid"]."' AND i.out_index='".$inputs[$j]["vout"]."';";
						$r = run_query($q);
						if (mysql_numrows($r) > 0) {
							$spend_io = mysql_fetch_array($r);
							$spend_io_ids[$j] = $spend_io['io_id'];
							$input_sum += $spend_io['amount'];
						}
						else {
							$transaction_error = true;
							$html .= "Error in block $block_id, Nothing found for: ".$q."\n";
						}
					}
					
					if (!$transaction_error && $input_sum >= $output_sum) {
						if (count($spend_io_ids) > 0) {
							$q = "UPDATE transaction_IOs SET spend_status='spent', spend_transaction_id='".$transaction['transaction_id']."' WHERE io_id IN (".implode(",", $spend_io_ids).");";
							$r = run_query($q);
							$html .= ", ";
						}
					}
					else {
						$html .= "Error in transaction #".$transaction['transaction_id']." (".$input_sum." vs ".$output_sum.")\n";
					}
				}
			}
			
			if ($new_block_id%$game['round_length'] == 0) add_round_from_rpc($game, $new_block_id/$game['round_length']);
		}
	}

	if ($tx_hash != "") {
		try {
			$raw_transaction = $empirecoin_rpc->getrawtransaction($tx_hash);
			$transaction_obj = $empirecoin_rpc->decoderawtransaction($raw_transaction);
			
			$q = "SELECT * FROM webwallet_transactions WHERE tx_hash='".$tx_hash."';";
			$r = run_query($q);
			if (mysql_numrows($r) > 0) {
				$transaction = mysql_fetch_array($r);
			}
			else {
				$outputs = $transaction_obj["vout"];
				$inputs = $transaction_obj["vin"];
				
				if (count($inputs) == 1 && $inputs[0]['coinbase']) {
					$transaction_type = "coinbase";
					if (count($outputs) > 1) $transaction_type = "votebase";
				}
				else $transaction_type = "transaction";
				
				$output_sum = 0;
				for ($j=0; $j<count($outputs); $j++) {
					$output_sum += pow(10,8)*$outputs[$j]["value"];
				}
				
				$q = "INSERT INTO webwallet_transactions SET game_id='".$game['game_id']."', amount='".$output_sum."', transaction_desc='".$transaction_type."', tx_hash='".$tx_hash."', address_id=NULL, block_id=NULL, time_created='".time()."';";
				$r = run_query($q);
				$db_transaction_id = mysql_insert_id();
				
				$q = "SELECT * FROM webwallet_transactions WHERE transaction_id='".$db_transaction_id."';";
				$r = run_query($q);
				$transaction = mysql_fetch_array($r);
				
				for ($j=0; $j<count($inputs); $j++) {
					$q = "SELECT * FROM webwallet_transactions t JOIN transaction_IOs i ON t.transaction_id=i.create_transaction_id WHERE t.tx_hash='".$inputs[$j]['txid']."' AND i.out_index='".$inputs[$j]['vout']."';";
					$r = run_query($q);
					if (mysql_numrows($r) == 1) {
						$db_input = mysql_fetch_array($r);
						$q = "UPDATE transaction_IOs SET spend_transaction_id='".$db_transaction_id."' WHERE io_id='".$db_input['io_id']."';";
						$r = run_query($q);
					}
				}
				
				for ($j=0; $j<count($outputs); $j++) {
					$address = $outputs[$j]["scriptPubKey"]["addresses"][0];
					
					$output_address = create_or_fetch_address($game, $address, true, $empirecoin_rpc, false);
					
					$q = "INSERT INTO transaction_IOs SET spend_status='unconfirmed', instantly_mature=0, game_id='".$game['game_id']."', out_index='".$j."', user_id='".$output_address['user_id']."', address_id='".$output_address['address_id']."'";
					if ($output_address['nation_id'] > 0) $q .= ", nation_id=".$output_address['nation_id'];
					$q .= ", create_transaction_id='".$db_transaction_id."', amount='".($outputs[$j]["value"]*pow(10,8))."';";
					$r = run_query($q);
				}
			}
		}
		catch (Exception $e) {
			$html .= "Please make sure that txindex=1 is included in your EmpireCoin.conf<br/>\n";
			$html .= "Exception Error:<br/>\n";
			$html .= json_encode($e);
			die();
		}
		
		set_site_constant('walletnotify', $tx_hash);
	}
	return $html;
}

function refresh_utxo_user_ids($only_unspent_utxos) {
	$update_user_id_q = "UPDATE transaction_IOs io JOIN addresses a ON io.address_id=a.address_id SET io.user_id=a.user_id";
	if ($only_unspent_utxos) $update_user_id_q .= " WHERE io.spend_status='unspent'";
	$update_user_id_q .= ";";
	$update_user_id_r = run_query($update_user_id_q);
}

function check_giveaway_available(&$game, $user, $invitation) {
	if ($game['game_type'] == "simulation" && ($game['giveaway_status'] == "on" || $game['giveaway_status'] == "invite_only")) {
		if ($game['giveaway_status'] == "invite_only") {
			$q = "SELECT * FROM invitations WHERE used_user_id='".$user['user_id']."' AND used_time=0 AND used=0;";
			$r = run_query($q);
			if (mysql_numrows($r) > 0) {
				$invitation = mysql_fetch_array($r);
				return true;
			}
			else return false;
		}
		else {
			$q = "SELECT * FROM webwallet_transactions t JOIN transaction_IOs io ON t.transaction_id=io.create_transaction_id WHERE io.game_id='".$game['game_id']."' AND io.user_id='".$user['user_id']."' AND t.transaction_desc='giveaway';";
			$r = run_query($q);
			if (mysql_numrows($r) > 0) return false;
			else return true;
		}
	}
	else return false;
}

function try_apply_giveaway($game, $user, $invitation) {
	$giveaway_available = check_giveaway_available($game, $user, $invitation);
	if ($giveaway_available) {
		$giveaway_block_id = last_block_id($game['game_id']);
		
		for ($i=0; $i<5; $i++) {
			$transaction_id = new_webwallet_multi_transaction($game, false, array(intval($game['giveaway_amount']/5)), false, $user['user_id'], $giveaway_block_id, 'giveaway', false, false, false, 0);
		}
		
		if ($invitation) {
			$q = "UPDATE invitations SET used_time='".time()."', used=1, used_ip='".mysql_real_escape_string($_SERVER['REMOTE_ADDR'])."' WHERE invitation_id='".$invitation['invitation_id']."';";
			$r = run_query($q);
		}
		return true;
	}
	else return false;
}

function get_user_strategy($user_id, $game_id, &$user_strategy) {
	$q = "SELECT * FROM user_strategies s JOIN user_games g ON s.strategy_id=g.strategy_id WHERE s.user_id='".$user_id."' AND g.game_id='".$game_id."';";
	$r = run_query($q);
	if (mysql_numrows($r) == 1) {
		$user_strategy = mysql_fetch_array($r);
		return true;
	}
	else {
		$user_strategy = false;
		return false;
	}
}

function output_message($status_code, $message, $dump_object) {
	if (!$dump_object) $dump_object = array("status_code"=>$status_code, "message"=>$message);
	else {
		$dump_object['status_code'] = $status_code;
		$dump_object['message'] = $message;
	}
	echo json_encode($dump_object);
}

function log_user_in(&$user, $viewer_id) {
	if ($GLOBALS['pageview_tracking_enabled']) {
		$q = "SELECT * FROM viewer_connections WHERE type='viewer2user' AND from_id='".$viewer_id."' AND to_id='".$user['user_id']."';";
		$r = run_query($q);
		
		if (mysql_numrows($r) == 0) {
			$q = "INSERT INTO viewer_connections SET type='viewer2user', from_id='".$viewer_id."', to_id='".$user['user_id']."';";
			$r = run_query($q);
		}
	}
	
	$session_key = session_id();
	$expire_time = time()+3600*24;
	
	$q = "INSERT INTO user_sessions SET user_id='".$user['user_id']."', session_key='".$session_key."', login_time='".time()."', expire_time='".$expire_time."'";
	if ($GLOBALS['pageview_tracking_enabled']) {
		$q .= ", ip_address='".$_SERVER['REMOTE_ADDR']."'";
	}
	$q .= ";";
	$r = run_query($q);
	
	$q = "UPDATE users SET logged_in=1";
	if ($GLOBALS['pageview_tracking_enabled']) {
		$q .= ", ip_address='".$_SERVER['REMOTE_ADDR']."'";
	}
	$q .= " WHERE user_id='".$user['user_id']."';";
	$r = run_query($q);
	
	if ($_REQUEST['invite_key'] != "") {
		try_apply_invite_key($user['user_id'], $_REQUEST['invite_key']);
	}
	
	$redirect_url_id = intval($_REQUEST['redirect_id']);
	$q = "SELECT * FROM redirect_urls WHERE redirect_url_id='".$redirect_url_id."';";
	$r = run_query($q);
	
	if (mysql_numrows($r) == 1) {
		$redirect_url = mysql_fetch_array($r);
		header("Location: ".$redirect_url['url']);
	}
	else header("Location: /wallet/");
	
	die();
}
function nation_flag($nation_id, $nation_name) {
	if (!$nation_name) {
		$nation = mysql_fetch_array(run_query("SELECT * FROM nations WHERE nation_id='".$nation_id."';"));
		$nation_name = $nation['name'];
	}
	return '<img class="small_flag" src="/img/flags/'.$nation_name.'.jpg" />&nbsp;';
}
function format_seconds($seconds) {
	$seconds = intval($seconds);
	$minutes = floor($seconds / 60);
	$seconds = $seconds % 60;
	
	if ($minutes > 0) return $minutes." minutes";
	else return $seconds." seconds";
}
?>