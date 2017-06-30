<?php
require_once '../common/common.php';

abstract class DownsampleMode {
  const AVERAGE = 1;
  const MIN_MAX = 2;
}

// For debugging:
// function ts($timestamp) {
//   return date('H:i:s', $timestamp / 1000);
// }

/**
 * General notes:
 * This uses REPLACE instead of INSERT because the client may resend the same timestamp (PK) when
 * the server's "OK" response gets lost (e.g. the client loses the uplink just before receiving the
 * server's response).
 */
class Database {
  /** Connects to the database, or exits on error. */
  public function __construct($createMissingTables = false) {
    $this->log = Logger::Instance();
    $this->mysqli = new mysqli(DB_SERVER, DB_USER, DB_PASS, DB_NAME);
    if ($this->mysqli->connect_errno) {
      $this->throwMySqlErrorException('__construct()');
    }
    if (!$this->mysqli->select_db(DB_NAME)) {
      $this->throwMySqlErrorException('select_db('.DB_NAME.')');
    }
    if ($createMissingTables) {
      $this->createMissingTables();
    }
    // Configure global logger.
    $config = $this->getConfig();
    if (array_key_exists('s:log_level', $config)) {
      $this->log->setLogLevelThreshold(strtolower($config['s:log_level']));
    }  // else: defaults to debug
  }

  public function beginTransaction() {
    $this->query('START TRANSACTION');
  }

  public function commit() {
    $this->query('COMMIT');
  }

  public function rollback() {
    $this->query('ROLLBACK');
  }

  public function createMissingTables() {
    $this->query('SET storage_engine=INNODB');
    $this->query(
        'CREATE TABLE IF NOT EXISTS temp (ts BIGINT PRIMARY KEY, t FLOAT NOT NULL)');
    $this->query(
        'CREATE TABLE IF NOT EXISTS wind (start_ts BIGINT PRIMARY KEY, '
        .'end_ts BIGINT NOT NULL, avg FLOAT NOT NULL, max FLOAT NOT NULL, max_ts BIGINT NOT NULL, '
        .'hist_id INT NOT NULL, buckets INT NOT NULL)');
    $this->query(
        'CREATE TABLE IF NOT EXISTS hist '
        .'(id INT PRIMARY KEY AUTO_INCREMENT, v INT NOT NULL, p FLOAT NOT NULL)');
    $this->query(
        'CREATE TABLE IF NOT EXISTS link (ts BIGINT PRIMARY KEY, nwtype VARCHAR(20), '
        .'strength TINYINT, upload BIGINT, download BIGINT)');
    $this->query(
        'CREATE TABLE IF NOT EXISTS meta (ts BIGINT PRIMARY KEY, upto BIGINT, cts BIGINT, '
        .'stratum INT, fails INT, ip VARCHAR(15))');
    $this->query(
        'CREATE TABLE IF NOT EXISTS door (ts BIGINT PRIMARY KEY, open BIT(1) NOT NULL)');
    $this->query(
        'CREATE TABLE IF NOT EXISTS pilots (ts BIGINT PRIMARY KEY, count INT NOT NULL)');
    $this->query(
        'CREATE TABLE IF NOT EXISTS temp_hum (ts BIGINT PRIMARY KEY, t FLOAT, h FLOAT)');
    $this->query(
        'CREATE TABLE IF NOT EXISTS adc (ts BIGINT, channel INT, value FLOAT, '
        .'PRIMARY KEY (ts, channel))');
    $this->query(
        'CREATE TABLE IF NOT EXISTS status (type VARCHAR(32) PRIMARY KEY, ts BIGINT NOT NULL, '
        .'text TEXT NOT NULL)');
    $this->query(
        'CREATE TABLE IF NOT EXISTS config (k VARCHAR(200) PRIMARY KEY, v TEXT NOT NULL)');
  }

  public function dropTablesExceptConfig() {
    $this->query('DROP TABLE IF EXISTS temp, wind, hist, link, meta, door, pilots, temp_hum, adc');
    $this->log->notice('tables dropped');
    unset($this->config);
  }

  /** Delete all records prior to $timestamp. */
  public function pruneTables($timestamp) {
    $this->query('DELETE FROM wind WHERE start_ts < '.$timestamp);
    $this->query('DELETE FROM hist WHERE id < (SELECT COALESCE('
      .'(SELECT hist_id FROM wind ORDER BY start_ts ASC LIMIT 1),'
      .'9223372036854775807))');  // maximum BIGINT; in case wind is empty
    foreach (array('temp', 'link', 'meta', 'door', 'pilots', 'temp_hum', 'adc') as $table) {
      $this->query('DELETE FROM '.$table.' WHERE ts < '.$timestamp);
    }
  }

  public function insertTemperature($temp) {
    if (count($temp) == 0) {
      $this->log->warning('received empty temperature measurements');
      return;
    }
    $q = '';
    foreach ($temp as $v) {
      if ($q) {
        $q .= ',';
      }
      $q .= '('.$v[0].','.$v[1].')';
    }

    $q = 'REPLACE INTO temp (ts, t) VALUES '.$q;
    $this->query($q);
  }

  public function insertLinkStatus($linkStatus) {
    if (count($linkStatus) == 0) {
      $this->log->warning('received empty link status');
      return;
    }
    $q = '';
    foreach ($linkStatus as $v) {
      if ($q) {
        $q .= ',';
      }
      $q .= '('.$v['ts'].',"'.$v['nwtype'].'",'.$v['strength'].
          ','.$v['upload'].','.$v['download'].')';
    }

    $q = 'REPLACE INTO link (ts, nwtype, strength, upload, download) VALUES '.$q;
    $this->query($q);
  }

  /**
   * Insert wind data.
   *
   * @param array $samples Samples provided by the client. Each is the result of one
   *     Wind.get_sample() call on the client.
   *
   * @return integer The latest end timestamp (for latency computation). 0 indicates no data.
   */
  public function insertWind($samples) {
    foreach ($samples as $stats) {
      // Insert histogram data first because we reference the hist ID in the wind table.
      $histogram = $stats['hist'];
      $histId = $this->insertHistogram($histogram);
      $buckets = count($histogram);
      $q = 'REPLACE INTO wind (start_ts, end_ts, avg, max, max_ts, hist_id, buckets) VALUES ('
          .$stats['start_ts'].','.$stats['end_ts'].','.$stats['avg'].','
          .$stats['max'].','.$stats['max_ts'].','.$histId.','.$buckets.')';
      $this->query($q);
    }

    $c = count($samples);
    return $c > 0 ? $samples[$c - 1]['end_ts'] : 0;
  }

  /** Returns the first (lowest) AUTO_INCREMENT ID generated, or NULL on error. */
  public function insertHistogram($histogram) {
    ksort($histogram);  // not required, but makes the table easier to read (for humans)
    $q = '';
    foreach ($histogram as $v => $p) {  // v=speed, p=percent
      if ($q) {
        $q .= ',';
      }
      $q .= '('.$v.','.$p.')';
    }
    $q = 'INSERT INTO hist (v, p) VALUES '.$q;
    $this->query($q);

    $row = $this->query('SELECT LAST_INSERT_ID()')->fetch_row();
    if (!$row[0]) {
      $this->throwException('SELECT LAST_INSERT_ID() -- returned no result');
    }
    return $row[0];
  }

  public function insertMetadata($meta) {
    $q = 'REPLACE INTO meta (ts, upto, cts, stratum, fails, ip) VALUES ('.timestamp().','
        .$meta['upto'].','.$meta['cts'].','.$meta['stratum'].','
        .$meta['fails'].',"'.$_SERVER['REMOTE_ADDR'].'")';
    $this->query($q);
  }

  public function insertTempHum($tempHum) {
    if (count($tempHum) == 0) {
      $this->log->warning('received empty temp_hum data');
      return;
    }
    $q = '';
    foreach ($tempHum as $v) {
      if (!($v[1] && $v[2])) {
        continue;  // reading the sensor may fail
      }
      if ($q) {
        $q .= ',';
      }
      $q .= '('.$v[0].','.$v[1].','.$v[2].')';
    }

    if ($q) {
      $q = 'REPLACE INTO temp_hum (ts, t, h) VALUES '.$q;
      $this->query($q);
    }
  }

  public function insertDoor($door) {
    if (count($door) == 0) {
      $this->log->warning('received empty door data');
      return;
    }
    $q = '';
    foreach ($door as $sample) {
      foreach ($sample as $event) {
        if ($q) {
          $q .= ',';
        }
        $q .= '('.$event[0].','.$event[1].')';
      }
    }
    $q = 'REPLACE INTO door (ts, open) VALUES '.$q;
    $this->query($q);
  }

  public function insertPilotCount($pilots) {
    if (count($pilots) == 0) {
      $this->log->warning('received empty pilots data');
      return;
    }
    $q = '';
    foreach ($pilots as $sample) {
      foreach ($sample as $count) {
        if ($q) {
          $q .= ',';
        }
        $q .= '('.$count[0].','.$count[1].')';
      }
    }
    $q = 'REPLACE INTO pilots (ts, count) VALUES '.$q;
    $this->query($q);
  }

  public function insertAdcValues($adc) {
    if (count($adc) == 0) {
      $this->log->warning('received empty ADC data');
      return;
    }
    $q = '';
    foreach ($adc as $sample) {
      foreach ($sample as $channel => $measurement) {
        $ts = $measurement[0];
        $value = $measurement[1];
        if ($q) {
          $q .= ',';
        }
        $q .= '('.$ts.','.$channel.','.$value.')';
      }
    }
    $q = 'REPLACE INTO adc (ts, channel, value) VALUES '.$q;
    $this->query($q);
  }

  public function insertStatus($status) {
    // TODO: Consider using ts as PK to keep history.
    $q = 'REPLACE INTO status (type, ts, text) VALUES ("client", '.$status[0].', "'.$status[1].'")';
    $this->query($q);
  }

  /**
   * Returns the sequence of changes to the door status, assuming that the door is initially (i.e.
   * before the start of the specified interval) closed (TODO: improve this). Duplicates the last
   * row for $endTimestamp. */
  public function readDoor($startTimestamp, $endTimestamp) {
    $q = 'SELECT ts, open FROM door WHERE ts >= '.$startTimestamp
        .' AND ts <= '.$endTimestamp.' ORDER BY ts';
    // TODO: Read one more row, so we know the state between $startTimestamp and the first row.
    $result = $this->query($q);
    $door = array(tokey($startTimestamp) => 0);  // assume door initially closed
    $previousOpen = 0;
    while ($row = $result->fetch_row()) {
      $ts = $row[0];
      $open = intval($row[1]);
      if ($open == $previousOpen) {
        continue;
      }
      $door[tokey($ts)] = $open;
      $previousOpen = $open;
    }
    $door[tokey($endTimestamp)] = $previousOpen;
    return $door;
  }

  /** Returns the pilot count over time. */
  public function readPilots($startTimestamp, $endTimestamp) {
    // Select the next smaller timestamp before $startTimestamp so we know the previous state.
    $q = 'SELECT ts, count FROM pilots '
        .'WHERE ts >= ( '
        .'  SELECT COALESCE( '
        .'    (SELECT ts FROM pilots WHERE ts < '.$startTimestamp.' ORDER BY ts DESC LIMIT 1), '
        .'    0 '
        .'  ) '
        .') '
        .'AND ts <= '.$endTimestamp.' ORDER BY ts';
    $result = $this->query($q);
    // In the special case where no previous data is available, guess 0.
    $pilots = array(tokey($startTimestamp) => 0);
    $count = 0;
    while ($row = $result->fetch_row()) {
      $count = intval($row[1]);
      $ts = $row[0];
      // The max() ensures that the range starts exactly at $startTimestamp even if $ts is smaller.
      // If there is a value exactly at $startTimestamp it will overwrite that value in the next
      // iteration. If there are no values before $startTimestamp at all the value remains 0.
      $pilots[tokey(max($startTimestamp, $ts))] = $count;
    }
    $pilots[tokey($endTimestamp)] = $count;
    return $pilots;
  }

  public function readStatus() {
    $q = 'SELECT ts, text FROM status WHERE type = "client"';
    $result = $this->query($q);
    $status = array();
    if ($row = $result->fetch_row()) {
      $status = array($row[0], $row[1]);
    }
    return $status;
  }

  /** Updates the specified config value. */
  public function setConfig($key, $value) {
    $q = 'REPLACE INTO config (k, v) VALUES ("'.$key.'", "'.$value.'")';
    $this->query($q);
    unset($this->config);
  }

  /** Deletes the specified config value. */
  public function clearConfig($key) {
    $q = 'DELETE FROM config WHERE k="'.$key.'"';
    $this->query($q);
    unset($this->config);
  }

  /** Returns application config. Lazily initialized. */
  public function getConfig() {
    if (!isset($this->config)) {
      $this->config = array();
      $result = $this->query('SELECT k, v FROM config ORDER BY k ASC');
      while ($row = $result->fetch_assoc()) {
        $this->config[$row['k']] = $row['v'];
      }
    }
    return $this->config;
  }

  /**
   * Builds the client config file contents. This includes all keys starting with 'c:'. The prefix
   * is stripped in the result.
   */
  public function createClientConfigFile() {
    $config = $this->getConfig();
    ksort($config);
    $buffer = '';
    foreach ($config as $k => $v) {
      if (strpos($k, 'c:') === 0) {
        $buffer .= substr($k, 2).'='.$v."\n";
      }
    }
    return $buffer;
  }

  /**
   * Compute statistics for the specified time period.
   *
   * @param int $startTimestamp Consider samples starting at this timestamp in millis.
   * @param int $endTimestamp Consider samples up to this end timestamp in millis (usually the
   *    current time).
   * @param int $outputLength Maximum number of samples in time series (will be downsampled if
   *    required).
   * @return array An array containing some scalar and the following non-scalar stats:
   *    'hist': An array of int(km/h) -> percentage.
   *    'time_series': A list of 3-tuples (timestamp, avg, max).
   */
  public function computeWindStats($startTimestamp, $endTimestamp, $outputLength) {
    $windowDuration = $endTimestamp - $startTimestamp;
    $q = 'SELECT start_ts, end_ts, avg, max, max_ts, hist_id, buckets FROM wind WHERE '
        .'start_ts >= '.$startTimestamp.' AND end_ts <= '.$endTimestamp.' ORDER BY start_ts DESC';
    $result = $this->query($q);

    // Read samples in reverse chronological order until the desired duration is best approximated.
    // At the same time, compute running stats except histogram.
    $actualStartTimestamp = 0;
    $actualEndTimestamp = 0;
    $actualWindowDuration = 0;
    $minHistId = 0;
    $maxHistId = 0;
    $maxKmh = 0;
    $maxTimestamp = 0;
    $avgKmh = 0;
    $selectedSamples = array();
    $timeSeries = array();
    while ($sample = $result->fetch_assoc()) {
      // TODO: What about gaps?
      // XXX: Remove this for timestamp API.
      // Can we approximate the desired duration better by including this row?
      $sampleDuration = Database::getSampleDuration($sample);
      if (abs($actualWindowDuration - $windowDuration) <
          abs($actualWindowDuration + $sampleDuration - $windowDuration)) {
        break;
      }
      $timeSeries[] = array(  // order as per WIND_SAMPLE_*
          $sample['start_ts'],
          $sample['end_ts'],
          $sample['avg'],
          $sample['max']  // TODO: Use max_ts for the max time series.
      );
      $selectedSamples[] = $sample;
      // Update times and IDs.
      $actualWindowDuration += $sampleDuration;
      $actualStartTimestamp = $sample['start_ts'];
      if (!$actualEndTimestamp) {
        $actualEndTimestamp = $sample['end_ts'];
      }
      $minHistId = $sample['hist_id'];
      if (!$maxHistId) {
        $maxHistId = $minHistId + $sample['buckets'] - 1;
      }
      // Update running stats except histogram.
      if ($sample['max'] > $maxKmh) {
        $maxKmh = $sample['max'];
        $maxTimestamp = $sample['max_ts'];
      }
      $avgKmh += $sample['avg'] * $sampleDuration;
    }

    if (count($selectedSamples) == 0) {
      return null;  // indicates that no data is available at all
    }

    $avgKmh /= $actualWindowDuration;

    // Compute histogram.
    $q = 'SELECT id, v, p from hist WHERE id >= '.$minHistId.' AND id <= '.$maxHistId
        .' ORDER BY id DESC';
    $result = $this->query($q);
    $histogram = array();
    $i = 0;
    $sampleDuration = Database::getSampleDuration($selectedSamples[0]);
    while ($bucket = $result->fetch_assoc()) {
      if ($bucket['id'] < $selectedSamples[$i]['hist_id']) {  // belongs to next (older) sample
        ++$i;
        $sampleDuration = Database::getSampleDuration($selectedSamples[$i]);
      }
      if ($bucket['id'] >= $selectedSamples[$i]['hist_id'] + $selectedSamples[$i]['buckets']) {
        // Without transaction this can happen if inserting the histogram succeeded but writing the
        // wind data failed. But even with transaction the server's "status: ok" reply might not
        // reach the client, so the client will resend the same data, which will overwrite the
        // previous data in all tables except hist, where it will add new rows.
        // In both cases there will be "orphaned" entries in the hist table that do not belong to
        // any row in the wind table. Skip them here.
        continue;
      }
      $v = intVal($bucket['v']);
      $histogram[$v] = get($histogram[$v], 0) + $bucket['p'] * $sampleDuration;
    }
    foreach ($histogram as $v => $p) {
      $histogram[$v] /= $actualWindowDuration;
    }
    ksort($histogram);
    // json_encode will emit a list if keys are consecutive and start at 0, otherwise a map with
    // string keys.

    return array(
        'avg' => $avgKmh,  // already float
        'max' => floatval($maxKmh),
        'max_ts' => $maxTimestamp,
        'hist' => $histogram,
        'start_ts' => $actualStartTimestamp,
        'end_ts' => $actualEndTimestamp,
        'time_series' => Database::downsampleWind($timeSeries, $outputLength)
    );
  }

  private static function getSampleDuration($sample) {
    return $sample['end_ts'] - $sample['start_ts'];
  }

  private static function downsampleWind($input, $outputLength) {
    $inputLength = count($input);
    $output = array();
    if ($inputLength <= $outputLength || $inputLength <= 1) {  // nothing to downsample
      foreach ($input as $sample) {
        $output[] = array(
            Database::center($sample[WIND_SAMPLE_START_TS], $sample[WIND_SAMPLE_END_TS]),
            floatval($sample[WIND_SAMPLE_AVG]),
            floatval($sample[WIND_SAMPLE_MAX])
        );
      }
      return $output;
    }
    // Values are in order of decreasing timestamp, i.e. earliest is last and latest is first.
    $startTs = $input[$inputLength - 1][WIND_SAMPLE_START_TS];
    $endTs = $input[0][WIND_SAMPLE_END_TS];
    $wi = 0;  // window index
    $windowStart = $startTs;
    $windowEnd = Database::getWindowEnd($startTs, $endTs, $outputLength, $wi);
    $window = Database::newWindow($windowStart, $windowEnd);
    $windowOverlap = 0;  // actually covered time in window (there might be gaps)
    $i = $inputLength - 1;  // input order is by decreasing timestamp, but we go forward in time
    while ($i >= 0) {  // Just for $inputLength == 0; we normally break from within the loop.
      // Shortcuts.
      $inputStart = $input[$i][WIND_SAMPLE_START_TS];
      $inputEnd = $input[$i][WIND_SAMPLE_END_TS];
      $inputCenter = Database::center($inputStart, $inputEnd);
      $inputAvg = $input[$i][WIND_SAMPLE_AVG];
      $inputMax = $input[$i][WIND_SAMPLE_MAX];

      // Does this input affect the current window?
      $overlap = min($windowEnd, $inputEnd) - max($windowStart, $inputStart);
//       echo '<p>i='.$i
//           .',is='.ts($inputStart).',ic='.ts($inputCenter).',ie='.ts($inputEnd)
//           .',ws='.ts($windowStart).',we='.ts($windowEnd).',ol='.$overlap.'</p>';
      if ($overlap > 0) {  // Yes -> update the current window.
        $windowOverlap += $overlap;
        $window[WIND_SAMPLE_AVG] += $inputAvg * $overlap;
        // Consider the maximum if the window includes the center of the current sample.
        if ($windowStart <= $inputCenter && $inputCenter < $windowEnd) {
          $window[WIND_SAMPLE_MAX] = max($window[WIND_SAMPLE_MAX], $inputMax);
        }
      }

      // Append the current window to the output if the current input already goes beyond it, or if
      // this is the last window.
      if ($inputEnd >= $windowEnd || $i == 0) {
        // Output the current window if it had any input overlap.
        if ($windowOverlap) {
          $avg = $window[WIND_SAMPLE_AVG] / $windowOverlap;
          $max = floatval($window[WIND_SAMPLE_MAX]);
          $output[] = array(
            Database::center($windowStart, $windowEnd),
            $avg,
            $max < 0 ? $avg : $max  // window might not have included a max sample point
          );
//           echo '<p><b>'.ts(Database::center($windowStart, $windowEnd)).'</b></p>';
        }
        if ($i == 0) {  // This was the last input.
          break;
        }
        // Proceed to the next window.
        $windowStart = $windowEnd;
        $windowEnd = Database::getWindowEnd($startTs, $endTs, $outputLength, ++$wi);
        $windowOverlap = 0;
        $window = Database::newWindow($windowStart, $windowEnd);
      } else {  // Next input might still overlap with the current window, so advance input.
        $i--;
      }
    }
    return $output;
  }

  private static function center($start, $end) {
    return ($end - $start) / 2 + $start;
  }

  /** Returns the end timestamp of the $wi-th window out of $outputLength windows. */
  private static function getWindowEnd($startTs, $endTs, $outputLength, $wi) {
    return (($endTs - $startTs) / $outputLength) * ($wi + 1) + $startTs;
  }

  private static function newWindow($windowStart, $windowEnd) {
    return array($windowStart, $windowEnd, 0, -1);  // number of elements as in WIND_SAMPLE_*
  }

  private static function downsampleTimeSeries($input, $outputLength,
      $mode = DownsampleMode::AVERAGE) {
    $inputLength = count($input);
    if ($inputLength <= $outputLength || $inputLength <= 1) {  // nothing to downsample
      return $input;
    }
    reset($input);
    $startTs = key($input);
    end($input);
    $endTs = key($input);
    $bucketIndex = 0;
    $bucketEndTs = Database::getBucketEndTs($startTs, $endTs, $outputLength, $bucketIndex);
    $values = array();
    $timestamps = array();
    $output = array();
    foreach ($input as $ts => $value) {
      // Still in current bucket?
      if ($ts < $bucketEndTs) {
        $values[] = $value;
        $timestamps[] = $ts;
      }
      // Output the bucket when it's finished or at the end of the iteration.
      if ($ts >= $bucketEndTs || $ts === $endTs) {
        if ($values) {
          $output[tokey(Database::average($timestamps))] =
              $mode === DownsampleMode::AVERAGE
              ? Database::average($values)
              : Database::minMax($values);  // DownsampleMode::MIN_MAX
        }
      }
      // Start a new bucket?
      if ($ts >= $bucketEndTs) {
        $values = array($value);
        $timestamps = array($ts);
        while ($ts >= $bucketEndTs) {  // skip empty buckets, if any
          $bucketEndTs = Database::getBucketEndTs($startTs, $endTs, $outputLength, ++$bucketIndex);
        }
      }
    }
    return $output;
  }

  /**
   * Return the end timestamp (exclusive) of the current bucket, or the pretty distant future if the
   * current bucket is the last bucket.
   */
  private static function getBucketEndTs($startTs, $endTs, $outputLength, $bucketIndex) {
    if ($bucketIndex + 1 == $outputLength) {
      return 1e13;  // last bucket catches all; 1e13 = year 2286 (and still fits in 32bit float)
    }
    return (($endTs - $startTs) / $outputLength) * ($bucketIndex + 1) + $startTs;
  }

  /** Compute the average of the specified array in a numerically stable way. */
  private static function average($values) {
    $average = 0;
    $n = count($values);
    foreach ($values as $v) {
      $average += $v / $n;
    }
    return $average;
  }

  private static function minMax($values) {
    return array(min($values), max($values));
  }

  public function readCpuTemp($startTimestamp, $endTimestamp, $timeSeriesPoints) {
    $q = 'SELECT ts, t FROM temp WHERE ts >= '.$startTimestamp
        .' AND ts <= '.$endTimestamp.' ORDER BY ts';
    $result = $this->query($q);
    $temp = array();
    while ($row = $result->fetch_row()) {
      $temp[tokey($row[0])] = floatval($row[1]);
    }
    return Database::downsampleTimeSeries($temp, $timeSeriesPoints);
  }

  public function readTempHum($startTimestamp, $endTimestamp, $timeSeriesPoints) {
    $q = 'SELECT ts, t, h FROM temp_hum WHERE ts >= '.$startTimestamp
        .' AND ts <= '.$endTimestamp.' ORDER BY ts';
    $result = $this->query($q);
    $temp = array();
    $hum = array();
    while ($row = $result->fetch_row()) {
      $temp[tokey($row[0])] = floatval($row[1]);
      $hum[tokey($row[0])] = floatval($row[2]);
    }
    return array(
        Database::downsampleTimeSeries($temp, $timeSeriesPoints),
        Database::downsampleTimeSeries($hum, $timeSeriesPoints));
  }

  public function readAdcValues($startTimestamp, $endTimestamp, $timeSeriesPoints) {
    $q = 'SELECT ts, channel, value FROM adc WHERE ts >= '.$startTimestamp
        .' AND ts <= '.$endTimestamp.' ORDER BY ts';
    $result = $this->query($q);
    $data = array();
    while ($row = $result->fetch_row()) {
      $ts = $row[0];
      $channel = $row[1];
      $value = floatval($row[2]);
      if (!array_key_exists($channel, $data)) {
        $data[$channel] = array();
      }
      $data[$channel][tokey($ts)] = $value;
    }
    foreach ($data as $channel => &$values) {
      $values = Database::downsampleTimeSeries($values, $timeSeriesPoints);
    }
    return $data;
  }

  public function readSignalStrength($startTimestamp, $endTimestamp, $timeSeriesPoints) {
    $q = 'SELECT ts, strength FROM link WHERE ts >= '.$startTimestamp
        .' AND ts <= '.$endTimestamp.' ORDER BY ts';
    $strength = array();
    $result = $this->query($q);
    while ($row = $result->fetch_row()) {
      $strength[tokey($row[0])] = intval($row[1]);
    }
    return Database::downsampleTimeSeries($strength, $timeSeriesPoints);
  }

  public function readNetworkType($startTimestamp, $endTimestamp) {
    $q = 'SELECT nwtype, count(*) FROM link'
        .' WHERE ts >= '.$startTimestamp.' AND ts <= '.$endTimestamp
        .' GROUP BY nwtype';
    $nwtypes = array();
    $result = $this->query($q);
    while ($row = $result->fetch_row()) {
      $nwtypes[$row[0]] = intval($row[1]);  // assume equal weight
    }
    return $nwtypes;
  }

  public function readTraffic($startTimestamp, $endTimestamp) {
    $q = 'SELECT ts, upload, download FROM link WHERE ts <= '.$endTimestamp
        .' ORDER BY ts DESC LIMIT 1';
    $result = $this->query($q);
    $actualStartTimestamp = $startTimestamp;
    $actualEndTimestamp = $endTimestamp;
    if ($row = $result->fetch_row()) {
      $actualEndTimestamp = $row[0];
      $upload = $row[1];
      $download = $row[2];
      $q = 'SELECT ts, upload, download FROM link WHERE ts <= '.$startTimestamp
          .' ORDER BY ts DESC LIMIT 1';
      $result = $this->query($q);
      if ($row = $result->fetch_row()) {
        $actualStartTimestamp = $row[0];
        $upload -= $row[1];
        $download -= $row[2];
      }
    } else {
      $upload = 0;
      $download = 0;
    }
    return array(
        'start_ts' => $actualStartTimestamp,
        'end_ts' => $actualEndTimestamp,
        'upload' => $upload,
        'download' => $download);
  }

  public function readLag($startTimestamp, $endTimestamp, $timeSeriesPoints) {
    // TODO: Filter rows with bad stratum (possibly require that the previous row is already good).
    $q = 'SELECT ts, stratum, upto FROM meta WHERE ts >= '
        .$startTimestamp.' AND ts <= '.$endTimestamp.' ORDER BY ts';
    $lag = array();
    $previousUpto = 0;
    $result = $this->query($q);
    while ($row = $result->fetch_assoc()) {
      $upto = $row['upto'];
      if (!$upto) {  // gaps (value 0) occur when no wind sample was present in the upload
        continue;
      }
      $ts = $row['ts'];
      // Synthesize one record just (1ms) prior to the upload to indicate the maximum lag.
      if ($lag) {
        $lag[tokey($ts - 1)] = $ts - $previousUpto;
      }
      $lag[tokey($ts)] = $ts - $upto;
      $previousUpto = $upto;
    }
    // Add the final lag (which might be significant).
    if ($previousUpto) {
      $ts = $endTimestamp;
      $lag[tokey($ts)] = $ts - $previousUpto;
    }
    return Database::downsampleTimeSeries($lag, $timeSeriesPoints, DownsampleMode::MIN_MAX);
  }

  /** Populate config table from ipa-default.cfg, keeping existing values. */
  public function populateConfig() {
    $cfg = file(CONFIG_DEFAULT_FILENAME, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $q = '';
    foreach ($cfg as $line) {
      list($key, $value) = explode('=', $line);
      if ($q) {
        $q .= ',';
      }
      $q .= '("'.$key.'","'.$value.'")';
    }
    $q = 'INSERT IGNORE INTO config (k, v) VALUES '.$q;
    $this->query($q, 'notice');
    unset($this->config);
  }

  public function echoConfig() {
    echo '<p><table border="1">';
    foreach ($this->getConfig() as $k => $v) {
      $component = '?';
      switch (substr($k, 0, 2)) {
        case 'c:':
          $component = 'client';
          $k = substr($k, 2);
          break;
        case 's:':
          $component = 'server';
          $k = substr($k, 2);
          break;
        default:
          break;
      }
      echo '<tr><td>'.$component.'</td><td>'.$k.'</td><td>'.$v.'</td></tr>';
    }
    echo '</table></p>';
  }

  /**
   * Runs the specified query, throwing an Exception on failure. Logs the query unconditionally with
   * the specified level (specify null to disable logging).
   */
  private function query($query, $logLevel = 'debug') {
    $this->log->log($logLevel, 'Query: '.$query);
    if ($result = $this->mysqli->query($query)) {
      return $result;
    }
    $this->throwMySqlErrorException($query);
  }

  private function throwMySqlErrorException($query) {
    $message = 'MySQL error '.$this->mysqli->errno.': '.$this->mysqli->error.' -- Query: '.$query;
    $this->throwException($message);
  }

  private function throwException($message) {
    $this->log->critical($message);
    throw new Exception($message);
  }
}
?>
