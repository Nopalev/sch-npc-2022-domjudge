<?php

/**
 * Functions for calculating the scoreboard.
 *
 * $Id$
 */


/** The calcScoreRow is in lib/lib.misc.php because it's used by other parts
 * of the system aswell.
 */


/**
 * Output the general scoreboard based on the cached data in table 'scoreboard'.
 * $myteamid can be passed to highlight a specific row.
 * $isjury set to true means the scoreboard will always be current, regardless of the
 * lastscoreupdate setting in the contesttable.
 */
function putScoreBoard($myteamid = null, $isjury = FALSE) {

	global $DB;

	$cid = getCurContest();
	$contdata = $DB->q('TUPLE SELECT * FROM contest WHERE cid = %i', $cid);
	
	// page heading with contestname and start/endtimes
	echo "<h1>Scoreboard ".htmlentities($contdata['contestname'])."</h1>\n\n";
	echo "<h4>starts: " . printtime($contdata['starttime']) .
	        " - ends: " . printtime($contdata['endtime']) . "</h4>\n\n";

	echo '<table class="scoreboard" cellpadding="3">' . "\n";


	// get the teams and problems
	$teams = $DB->q('KEYTABLE SELECT login AS ARRAYKEY, login, name, category FROM team');
	$probs = $DB->q('KEYTABLE SELECT probid AS ARRAYKEY, probid, name FROM problem
		WHERE cid = %i AND allow_submit = 1 ORDER BY probid', $cid);

	// output table column groups (for the styles)
	echo '<colgroup><col id="scoreteamname" /><col id="scoresolv" /><col id="scoretotal" />';
	for( $i = 0; $i < count($probs); $i++ ) {
		echo '<col class="scoreprob" />';
	}
	echo "</colgroup>\n";

	// column headers
	echo '<tr id="scoreheader"><th>TEAM</th>';
	echo "<th>solved</th><th>time</th>\n";
	foreach( $probs as $pr ) {
		echo '<th title="' . htmlentities($pr['name']). '">' .
			htmlentities($pr['probid']) . '</th>';
	}
	echo "</tr>\n";

	// initialize the arrays we'll build from the data
	$THEMATRIX = $SCORES = array();
	$SUMMARY = array('num_correct' => 0, 'total_time' => 0);

	// Get all stuff from the cached table, but don't bother with outdated
	// info from previous contests.
	
	if ( $isjury ) {
		$cachetable = 'scoreboard_jury';
	} else {
		$cachetable = 'scoreboard_public';
	}
	
	$scoredata = $DB->q("SELECT * FROM $cachetable WHERE cid = %i", $cid);

	// the SCORES table contains the totals for each team which we will
	// use for determining the ranking. Initialise them here
	foreach ($teams as $login => $team ) {
		$SCORES[$login]['num_correct'] = 0;
		$SCORES[$login]['total_time']  = 0;
		$SCORES[$login]['teamname']    = $team['name'];
		$SCORES[$login]['category']    = $team['category'];
	}

	// loop all info the scoreboard cache and put it in our own datastructure
	while ( $srow = $scoredata->next() ) {
	
		// skip this row if the team or problem is not known by us
		if ( ! array_key_exists ( $srow['team'], $teams ) ||
			 ! array_key_exists ( $srow['problem'], $probs ) ) {
			continue;
		}
	
		// fill our matrix with the scores from the database,
		// we'll print this out later when we've sorted the teams
		$THEMATRIX[$srow['team']][$srow['problem']] = array (
				'correct' => (bool) $srow['is_correct'],
				'submitted' => $srow['submissions'],
				'time' => $srow['totaltime'],
				'penalty' => $srow['penalty'] );

		// calculate totals for this team
		if ( $srow['is_correct'] ) {
			$SCORES[$srow['team']]['num_correct'] ++;
		}
		$SCORES[$srow['team']]['total_time'] += $srow['totaltime'] + $srow['penalty'];

	}

	// sort the array using our custom comparison function
	uasort($SCORES, 'cmp');

	// print the whole thing
	foreach( $SCORES as $team => $totals ) {

		// team name, total correct, total time
		echo '<tr' . ( @$myteamid == $team ? ' id="scorethisisme"' : '' ) .
			' class="category' . $totals['category'] . '">' .
			'<td class="scoretn">' . htmlentities($teams[$team]['name']) . '</td>' .
			'<td class="scorenc">' . $totals['num_correct'] . '</td>' .
			'<td class="scorett">' . $totals['total_time'] . '</td>';

		// keep summary statistics for the bottom row of our table
		$SUMMARY['num_correct'] += $totals['num_correct'];
		$SUMMARY['total_time']  += $totals['total_time'];

		// for each problem
		foreach( $THEMATRIX[$team] as $prob => $pdata ) {
			echo '<td class="';
			// CSS class for correct/incorrect/neutral results
			if( $pdata['correct'] ) { 
				echo 'score_correct';
			} elseif ( $pdata['submitted'] > 0 ) {
				echo 'score_incorrect';
			} else {
				echo 'score_neutral';
			}
			// number of submissions for this problem
			echo '">' . $pdata['submitted'];
			// if correct, print time scored
			if( ($pdata['time']+$pdata['penalty']) > 0) {
				echo " (" . $pdata['time'] . ' + ' . $pdata['penalty'] . ")";
			}
			echo "</td>";
			@$SUMMARY[$prob]['submissions'] += $pdata['submitted'];
			@$SUMMARY[$prob]['correct'] += ($pdata['correct'] ? 1 : 0);
			if( $pdata['time'] > 0 ) {
				@$SUMMARY[$prob]['times'][] = $pdata['time'];
			}
		}
		echo "</tr>\n";

	}

	// print a summaryline
	echo "\n<tr id=\"scoresummary\"><td>Summary</td>";
	echo '<td class="scorenc">' . $SUMMARY['num_correct'] . '</td>' .
	     '<td class="scorett">' . $SUMMARY['total_time'] . '</td>';
	foreach( $probs as $pr ) {
		echo '<td>' . $SUMMARY[$pr['probid']]['submissions'] . ' / ' .
			$SUMMARY[$pr['probid']]['correct'] . ' / ' .
			( isset($SUMMARY[$pr['probid']]['times']) ? min(@$SUMMARY[$pr['probid']]['times']) : '-') . "</td>";
	}
	echo "</tr>\n\n";

	echo "</table>\n\n";

	$res = $DB->q('SELECT * FROM category ORDER BY catid');

	// only print legend when there's more than one category
	if ( $res->count() > 1 ) {
		echo "<br /><br /><br />\n<table class=\"scoreboard\"><tr><th>Legend</th></tr>\n";
		while ( $row = $res->next() ) {
			echo '<tr class="category' . $row['catid'] . '">' .
				'<td align="center" class="scoretn">' .	$row['name'] . "</td></tr>";
		}
		echo "</table>\n\n";
	}

	// last modified date, now if we are the jury, else include the lastscoreupdate time
	if( ! $isjury && isset($contdata['lastscoreupdate']) ) {
		$lastupdate = min(time(), strtotime($contdata['lastscoreupdate']));
	} else {
		$lastupdate = time();
	}
	echo "<span id=\"lastmod\">Last Update: " .
		date('j M Y H:i', $lastupdate) . "</span>\n\n";

	return;
}

// comparison function for scoreboard
function cmp ($a, $b) {
	// more correct than someone else means higher rank
	if ( $a['num_correct'] != $b['num_correct'] ) {
		return $a['num_correct'] > $b['num_correct'] ? -1 : 1;
	}
	// else, less time spent means higher rank
	if ( $a['total_time'] != $b['total_time'] ) {
		return $a['total_time'] < $b['total_time'] ? -1 : 1;
	}
	// else, order by teamname alphabetically
	if ( $a['teamname'] != $b['teamname'] ) {
		return $a['teamname'] < $b['teamname'] ? -1 : 1;
	}
	// undecided, should never happen in practice
	return 0;
}

