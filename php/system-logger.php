<?php
class Logger {
    private $status = [ 'Success', 'Warning', 'Error', 'Security' ];
    private $log_file = __DIR__ . '/log/system-log.csv';
    private $filename;
    private $version;
    private $ip;

    public function __construct($filename, $version, $ip) {
        $this->filename = $filename;
        $this->version = $version;
        $this->ip = $ip;
    }

    public function write($status_id, $message, $log=null, $end_code=null) {
      try {
        $time = date('Y-m-d H:i:s');
        $status = $this->status[$status_id] ?? 'Unknown';
        $data = array($time, $status, $this->filename, $this->version, $this->ip, $message, $log);
        $fp = fopen($this->log_file, 'a');
        fputcsv($fp, $data);
        fclose($fp);
        if ($end_code !== null) {
          http_response_code($end_code);
          exit;
        }
      } catch (Exception $e) {
        error_log($e->getMessage());
      }
    }
}
