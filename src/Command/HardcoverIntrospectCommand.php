<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Integration;
use App\Repository\IntegrationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'spinescout:hardcover:introspect', description: 'Dump the fields of a Hardcover GraphQL type for shaping queries.')]
final class HardcoverIntrospectCommand extends Command
{
    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('type', InputArgument::OPTIONAL, 'GraphQL type name to introspect.', 'TrendingBookType');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $integration = $this->integrations->findByKind(Integration::KIND_HARDCOVER);
        if ($integration === null) {
            $output->writeln('<error>No Hardcover integration row.</error>');
            return Command::FAILURE;
        }
        $token = (string) ($integration->getCredentials()['token'] ?? '');
        if ($token === '') {
            $output->writeln('<error>No Hardcover token saved.</error>');
            return Command::FAILURE;
        }

        $typeName = (string) $input->getArgument('type');
        if ($typeName === '__redrising_probe') {
            $query = <<<'GQL'
                query Probe {
                  by_slug: books(where: {slug: {_eq: "red-rising"}}, limit: 1) {
                    id
                    title
                    editions(limit: 200, where: {_or: [{isbn_10: {_is_null: false}}, {isbn_13: {_is_null: false}}]}) {
                      isbn_13 users_count language { code3 }
                    }
                  }
                  edition_count: editions(where: {book_id: {_eq: 427473}, _or: [{isbn_10: {_is_null: false}}, {isbn_13: {_is_null: false}}]}) {
                    isbn_13 users_count language { code3 }
                  }
                  by_isbn: editions(where: {isbn_13: {_eq: "9780345539786"}}, limit: 5) {
                    isbn_10 isbn_13 physical_format users_count
                    book { id title slug }
                    language { code3 } country { code2 }
                  }
                }
                GQL;
        } else {
            $query = <<<GQL
                query Introspect {
                  __type(name: "$typeName") {
                    name
                    kind
                    fields {
                      name
                      type { name kind ofType { name kind ofType { name kind } } }
                    }
                    inputFields {
                      name
                      type { name kind ofType { name kind } }
                    }
                  }
                }
                GQL;
        }

        $response = $this->httpClient->request('POST', 'https://api.hardcover.app/v1/graphql', [
            'headers' => [
                'Authorization' => str_starts_with($token, 'Bearer ') ? $token : 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => 'SpineScout/1.0',
            ],
            'json' => ['query' => $query],
        ]);
        $output->writeln(json_encode($response->toArray(false), JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}
