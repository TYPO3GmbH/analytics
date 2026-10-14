<?php

declare(strict_types=1);

namespace T3G\Analytics\Utility;

use Psr\Http\Message\ResponseInterface;

/**
 * Reads the responses of the analytics API into plain arrays.
 */
final class AnalyticsResponseUtility
{
    /**
     * Decodes one result of a settled request group, see GuzzleHttp\Promise\Utils::settle().
     *
     * @param array{state: string, value?: ResponseInterface, reason?: \Throwable} $result
     * @return array<string, mixed>
     * @throws \Throwable The reason of a rejected request
     */
    public static function decodeSettledResponse(array $result): array
    {
        if ($result['state'] !== 'fulfilled' || !isset($result['value'])) {
            throw $result['reason'] ?? new \RuntimeException('Unknown error');
        }
        $body = json_decode((string)$result['value']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return is_array($body) ? $body : [];
    }

    /**
     * Without dimensions the payload holds one row with the totals of the range.
     *
     * @param array<string, mixed> $body
     * @return array{visitCount: int, visitorCount: int, bounceRate: float}
     */
    public static function sessionTotals(array $body): array
    {
        $row = is_array($body['payload'] ?? null) ? ($body['payload'][0] ?? []) : [];
        return self::sessionMetrics(is_array($row) ? $row : []);
    }

    /**
     * @param array<mixed> $row
     * @return array{visitCount: int, visitorCount: int, bounceRate: float}
     */
    public static function sessionMetrics(array $row): array
    {
        return [
            'visitCount' => (int)($row['visitCount'] ?? 0),
            'visitorCount' => (int)($row['visitorCount'] ?? 0),
            'bounceRate' => (float)($row['bounceRate'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $sessionsBody
     * @param array{dates: list<string>, values: list<int>, average: int}|null $timeOnPage
     * @return array{dates: list<string>, visits: list<int>, visitors: list<int>, bounceRate: list<float>, avgTimeOnPage: list<int>}
     */
    public static function dailySeries(array $sessionsBody, ?array $timeOnPage): array
    {
        $series = ['dates' => [], 'visits' => [], 'visitors' => [], 'bounceRate' => [], 'avgTimeOnPage' => []];
        // Both endpoints bucket by UTC day, so the date is the common key of the two series.
        $timeOnPageByDate = $timeOnPage !== null ? array_combine($timeOnPage['dates'], $timeOnPage['values']) : [];

        foreach (is_array($sessionsBody['payload'] ?? null) ? $sessionsBody['payload'] : [] as $row) {
            if (!is_array($row) || !is_string($row['date'] ?? null)) {
                continue;
            }
            $date = substr($row['date'], 0, 10);
            $metrics = self::sessionMetrics($row);
            $series['dates'][] = $date;
            $series['visits'][] = $metrics['visitCount'];
            $series['visitors'][] = $metrics['visitorCount'];
            $series['bounceRate'][] = $metrics['bounceRate'];
            $series['avgTimeOnPage'][] = $timeOnPageByDate[$date] ?? 0;
        }
        return $series;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{dates: list<string>, values: list<int>, average: int}
     */
    public static function timeOnPageGraph(array $body): array
    {
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $dataset = is_array($payload['datasets'][0] ?? null) ? $payload['datasets'][0] : [];
        $labels = is_array($payload['labels'] ?? null) ? array_values($payload['labels']) : [];
        $data = is_array($dataset['data'] ?? null) ? array_values($dataset['data']) : [];
        $count = min(count($labels), count($data));
        return [
            'dates' => array_map(static fn (mixed $label): string => substr((string)$label, 0, 10), array_slice($labels, 0, $count)),
            'values' => array_map(static fn (mixed $value): int => (int)round(is_numeric($value) ? (float)$value : 0.0), array_slice($data, 0, $count)),
            'average' => (int)round(is_numeric($dataset['totalAverage'] ?? null) ? (float)$dataset['totalAverage'] : 0.0),
        ];
    }

    /**
     * Reads the first dataset of a graph response.
     *
     * @param array<string, mixed> $body
     * @return array{data: list<int|float>, labels: list<string>}
     */
    public static function timeSeriesData(array $body): array
    {
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $datasets = is_array($payload['datasets'] ?? null) ? $payload['datasets'] : [];
        $rawData = is_array($datasets[0]['data'] ?? null) ? $datasets[0]['data'] : [];
        $rawLabels = is_array($payload['labels'] ?? null) ? $payload['labels'] : [];
        return [
            'data' => array_values(array_map(static fn (mixed $v): int|float => is_numeric($v) ? (float)$v : 0, $rawData)),
            'labels' => array_values(array_map(static fn (mixed $v): string => (string)$v, $rawLabels)),
        ];
    }

    /**
     * Reads the labels and all datasets of a graph response.
     *
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, total: int}>}
     */
    public static function graph(mixed $payload): array
    {
        $payload = is_array($payload) ? $payload : [];
        /** @var list<string> $labels */
        $labels = is_array($payload['labels'] ?? null) ? array_values($payload['labels']) : [];
        /** @var list<array{label: string, data: list<int>, total: int}> $datasets */
        $datasets = is_array($payload['datasets'] ?? null) ? array_values($payload['datasets']) : [];
        return ['labels' => $labels, 'datasets' => $datasets];
    }
}
