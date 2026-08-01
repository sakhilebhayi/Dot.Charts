<?php

namespace App\Services;

class SignalFeedbackService
{
    protected $feedbackFile;

    /**
     * @param string|null $feedbackFile Optional override, mainly for tests.
     *                                  Defaults to storage/signal_feedback.json.
     */
    public function __construct(?string $feedbackFile = null)
    {
        $this->feedbackFile = $feedbackFile ?? __DIR__ . '/../../storage/signal_feedback.json';
    }

    /**
     * Store user feedback for a signal
     * @param string $symbol
     * @param string $signal
     * @param string $feedback ('accurate', 'inaccurate', 'neutral')
     * @param string|null $comment
     * @return bool
     */
    public function submitFeedback(string $symbol, string $signal, string $feedback, ?string $comment = null): bool
    {
        $data = $this->loadFeedback();
        $entry = [
            'timestamp' => date('c'),
            'symbol' => $symbol,
            'signal' => $signal,
            'feedback' => $feedback,
            'comment' => $comment
        ];
        $data[] = $entry;
        return $this->saveFeedback($data);
    }

    /**
     * Get all feedback entries
     * @return array
     */
    public function getFeedback(): array
    {
        return $this->loadFeedback();
    }

    /**
     * Aggregate feedback for a symbol
     * @param string $symbol
     * @return array
     */
    public function aggregateFeedback(string $symbol): array
    {
        $data = $this->loadFeedback();
        $stats = ['accurate' => 0, 'inaccurate' => 0, 'neutral' => 0, 'total' => 0];
        foreach ($data as $entry) {
            if ($entry['symbol'] === $symbol) {
                $stats[$entry['feedback']]++;
                $stats['total']++;
            }
        }
        return $stats;
    }

    protected function loadFeedback(): array
    {
        if (!file_exists($this->feedbackFile)) return [];
        $json = file_get_contents($this->feedbackFile);
        return json_decode($json, true) ?? [];
    }

    protected function saveFeedback(array $data): bool
    {
        return file_put_contents($this->feedbackFile, json_encode($data, JSON_PRETTY_PRINT)) !== false;
    }
}
