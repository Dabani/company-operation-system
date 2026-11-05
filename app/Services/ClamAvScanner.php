<?php

namespace App\Services;

/**
 * ClamAV (clamd) INSTREAM scanner. Enable with CLAMAV_ENABLED=true; see config/ops.php 'clamav'.
 * Fail-closed by default: if the daemon cannot be reached the file is NOT accepted (mandatory for client uploads).
 */
class ClamAvScanner implements FileScanner
{
    public function isClean(string $absolutePath): bool
    {
        $cfg = config('ops.clamav');
        $target = ($cfg['socket'] ?? null) ? 'unix://' . $cfg['socket'] : 'tcp://' . $cfg['host'] . ':' . $cfg['port'];
        $fh = @fopen($absolutePath, 'rb');
        $sock = @stream_socket_client($target, $errno, $err, (float) $cfg['timeout']);
        if (!$fh || !$sock) {
            if ($fh) fclose($fh);
            return !($cfg['fail_closed'] ?? true);
        }
        stream_set_timeout($sock, (int) $cfg['timeout']);
        fwrite($sock, "zINSTREAM\0");
        while (!feof($fh)) {
            $chunk = fread($fh, 8192);
            if ($chunk === false || $chunk === '') break;
            fwrite($sock, pack('N', strlen($chunk)) . $chunk);
        }
        fwrite($sock, pack('N', 0));
        $reply = trim((string) stream_get_contents($sock), "\0 \n");
        fclose($sock);
        fclose($fh);
        return str_ends_with($reply, 'OK');                         // "stream: OK" | "stream: Eicar-Test-Signature FOUND" | "...ERROR"
    }
}
