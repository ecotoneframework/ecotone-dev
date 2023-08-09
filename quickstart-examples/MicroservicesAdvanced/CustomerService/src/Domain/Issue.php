<?php

namespace App\Microservices\CustomerService\Domain;

use App\Microservices\CustomerService\Domain\Command\ReportIssue;
use App\Microservices\CustomerService\Domain\Event\IssueWasClosed;
use App\Microservices\CustomerService\Domain\Event\IssueWasReported;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\AggregateIdentifierMethod;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\WithAggregateEvents;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

#[Aggregate]
final class Issue
{
    use WithAggregateEvents;

    const REPORT_ISSUE = "issue.report";
    const CLOSE_ISSUE = "issue.close";

    #[Identifier]
    private UuidInterface $issueId;
    private Email $email;
    private string $content;
    private bool $ongoing;

    #[CommandHandler(Issue::REPORT_ISSUE)]
    public static function reportNew(ReportIssue $command): self
    {
        $issue = new self();
        $issue->issueId = $command->issueId;
        $issue->email = $command->email;
        $issue->ongoing = true;
        $issue->content = $command->content;

        $issue->recordThat(new IssueWasReported($issue->issueId));

        return $issue;
    }

    #[CommandHandler(self::CLOSE_ISSUE)]
    public function close(): void
    {
        if (!$this->ongoing) {
            return;
        }

        $this->ongoing = false;

        $this->recordThat(new IssueWasClosed($this->issueId));
    }

    public function getIssueId(): UuidInterface
    {
        return $this->issueId;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
