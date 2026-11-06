<?php
declare(strict_types=1);

/** Anonymous tile interactions plus the owner's configured promotional display baseline. */
final class TileEngagementStore
{
    private string $directory;
    private string $site;
    private string $start;

    public function __construct(string $directory, string $site, string $start = '2026-11-06')
    {
        $this->directory = $directory;
        $this->site = $site;
        $this->start = $start;
    }

    public static function validKey($key): bool
    {
        return is_string($key) && preg_match('/^(?:tile-[a-f0-9]{16}|course-[a-z0-9-]{1,64})$/D', $key) === 1;
    }

    private function number(string $value): int
    {
        return (int) hexdec(substr(hash('sha256', $this->site . ':' . $value), 0, 7));
    }

    public function promotion(string $key, string $day): array
    {
        $popular = preg_match('/^course-(?:dca|adca)(?:-|$)/', $key) === 1;
        $likes = ($popular ? 12000 : 5000) + $this->number($key . ':likes') % 3001;
        $views = max(($popular ? 16000 : 7000) + $this->number($key . ':views') % 3001, $likes + 1500);
        $zone = new DateTimeZone('Asia/Kolkata');
        $from = new DateTimeImmutable($this->start, $zone);
        $to = new DateTimeImmutable($day, $zone);
        $days = max(0, (int) $from->diff($to)->format('%r%a'));
        // A seeded daily schedule avoids cron jobs and does not reset on refresh/deployment.
        $steps = [25, 40, 50, 80, 100];
        $cycles = intdiv($days, 365);
        $remainder = $days % 365;
        $sumLikes = $sumViews = $partLikes = $partViews = 0;
        for ($i = 0; $i < 365; $i++) {
            $a = $steps[$this->number($key . ':daily-like:' . $i) % 5];
            $b = max($a, $steps[$this->number($key . ':daily-view:' . $i) % 5]);
            $sumLikes += $a;
            $sumViews += $b;
            if ($i < $remainder) { $partLikes += $a; $partViews += $b; }
        }
        $likes += $cycles * $sumLikes + $partLikes;
        $views += $cycles * $sumViews + $partViews;
        return ['likes' => $likes, 'views' => max($views, $likes + 1)];
    }

    private function transact(callable $operation): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Counter storage unavailable.');
        }
        $lock = @fopen($this->directory . '/counters.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Counter storage unavailable.');
        try {
            $path = $this->directory . '/counters.json';
            if (is_file($path)) {
                $raw = file_get_contents($path);
                $state = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
                if (!isset($state['secret'], $state['cards'], $state['rate'])) throw new RuntimeException('Counter data invalid.');
            } else {
                $state = ['version'=>1, 'secret'=>bin2hex(random_bytes(32)), 'cards'=>[], 'rate'=>[]];
            }
            $result = $operation($state);
            $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $temp = tempnam($this->directory, 'counter-');
            if ($temp === false) throw new RuntimeException('Counter save unavailable.');
            try {
                if (file_put_contents($temp, $json) !== strlen($json)) throw new RuntimeException('Counter save failed.');
                chmod($temp, 0600);
                if (!rename($temp, $path)) throw new RuntimeException('Counter save failed.');
            } finally { if (is_file($temp)) unlink($temp); }
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function request(array $input, string $visitor, string $network, ?string $day = null, ?int $time = null): array
    {
        $day = $day ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        $time = $time ?? time();
        $action = $input['action'] ?? 'sync';
        $keys = $input['keys'] ?? [];
        if (!in_array($action, ['sync', 'view', 'like'], true) || !is_array($keys) || count($keys) > 120 || !$keys) {
            throw new InvalidArgumentException('Invalid counter request.');
        }
        foreach ($keys as $key) if (!self::validKey($key)) throw new InvalidArgumentException('Invalid tile.');
        $keys = array_values(array_unique($keys));
        if ($action === 'like' && (count($keys) !== 1 || !isset($input['liked']) || !is_bool($input['liked']))) {
            throw new InvalidArgumentException('Invalid like request.');
        }
        return $this->transact(function (array &$state) use ($input, $visitor, $network, $keys, $action, $day, $time): array {
            $person = hash_hmac('sha256', $visitor, $state['secret']);
            $token = hash_hmac('sha256', $person . ':' . $day, $state['secret']);
            if ($action !== 'sync' && !hash_equals($token, (string) ($input['token'] ?? ''))) throw new DomainException('Reload this page.');
            $minute = intdiv($time, 60);
            foreach ($state['rate'] as $id => $rate) if (($rate['minute'] ?? 0) < $minute - 2) unset($state['rate'][$id]);
            $rateKey = hash_hmac('sha256', $network, $state['secret']);
            $rate = $state['rate'][$rateKey] ?? ['minute'=>$minute, 'count'=>0];
            if ($rate['minute'] !== $minute) $rate = ['minute'=>$minute, 'count'=>0];
            if ($rate['count'] >= 300) throw new OverflowException('Please wait before trying again.');
            $rate['count']++;
            $state['rate'][$rateKey] = $rate;
            $result = [];
            foreach ($keys as $key) {
                if (!isset($state['cards'][$key])) {
                    if (count($state['cards']) >= 5000) throw new OverflowException('Counter catalogue is full.');
                    $state['cards'][$key] = ['likes'=>0, 'views'=>0, 'voters'=>[], 'seen'=>[]];
                }
                $row =& $state['cards'][$key];
                if ($action === 'view' && ($row['seen'][$person] ?? '') !== $day) {
                    $row['views']++;
                    $row['seen'][$person] = $day;
                }
                if ($action === 'like') {
                    $liked = isset($row['voters'][$person]);
                    if ($input['liked'] && !$liked) { $row['voters'][$person] = true; $row['likes']++; }
                    if (!$input['liked'] && $liked) { unset($row['voters'][$person]); $row['likes'] = max(0, $row['likes'] - 1); }
                }
                if (($row['promotion_day'] ?? '') !== $day) {
                    $row['promotion'] = $this->promotion($key, $day);
                    $row['promotion_day'] = $day;
                    $cutoff = (new DateTimeImmutable($day))->modify('-2 days')->format('Y-m-d');
                    foreach ($row['seen'] as $id => $seenDay) if ($seenDay < $cutoff) unset($row['seen'][$id]);
                }
                $result[$key] = ['likes'=>$row['promotion']['likes'] + $row['likes'], 'views'=>$row['promotion']['views'] + $row['views'], 'liked'=>isset($row['voters'][$person])];
                unset($row);
            }
            return ['cards'=>$result, 'token'=>$token, 'day'=>$day];
        });
    }
}
