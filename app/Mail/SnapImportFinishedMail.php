<?php

namespace App\Mail;

use App\Models\SnapImport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SnapImportFinishedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public SnapImport $import
    ) {}

    public function build(): self
    {
        $this->import->loadMissing('template');

        $status = $this->import->status;

        $subject = match ($status) {
            SnapImport::STATUS_COMPLETED => 'Your snap import is complete',
            SnapImport::STATUS_FAILED => 'Your snap import failed',
            SnapImport::STATUS_CANCELED => 'Your snap import was canceled',
            default => 'Your snap import update',
        };

        return $this->subject($subject)
            ->markdown('emails.snap-import-finished')
            ->with([
                'import' => $this->import,
                'status' => $status,
                'template' => $this->import->template,
                'progress' => $this->import->progressSnapshot(),
            ]);
    }
}
