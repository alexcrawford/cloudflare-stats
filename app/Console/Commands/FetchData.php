<?php


namespace App\Console\Commands;

use App\Models\Day;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FetchData extends Command
{
    protected $signature = 'fetch';

    public function handle()
    {
        $client = new Client(
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . env('CLOUDFLARE_TOKEN'),
                    'X-AUTH-EMAIL'  => env('CLOUDFLARE_EMAIL'),
                ],
            ]
        );

        $httpRequestsAdaptiveGroupsLimit = Carbon::now()->subDays(7);
        $httpRequests1dGroupsLimit = Carbon::now()->subDays(364);
        $lastUpdated = Carbon::parse(Day::max('date') ?? $httpRequests1dGroupsLimit);
        $httpRequests1dGroupsStart = $lastUpdated->gt($httpRequests1dGroupsLimit) ? $lastUpdated :
            $httpRequests1dGroupsLimit;
        $httpRequestsAdaptiveGroupsStart = $lastUpdated->gt($httpRequestsAdaptiveGroupsLimit) ? $lastUpdated :
            $httpRequestsAdaptiveGroupsLimit;

        $response = $client->post(
            'https://api.cloudflare.com/client/v4/graphql',
            [
                'json' => [
                    'query'     =>
'{
  viewer {
    zones(filter: {zoneTag: $zoneTag}) {
      httpRequestsAdaptiveGroups(orderBy: [date_ASC], limit: 7, filter: {date_geq: $httpRequestsAdaptiveGroupsStart, date_leq: $endDate}) {
        dimensions {
          date
        }
        sum {
          visits
        }
      },
      httpRequests1dGroups(orderBy: [date_ASC], limit: 365, filter: {date_geq: $httpRequests1dGroupsStart, date_leq: $endDate}) {
        dimensions {
          date
        }
        sum {
          pageViews
        }
        uniq {
          uniques
        }
      }
    }
  }
}',
                    'variables' => [
                        'zoneTag'                         => env('CLOUDFLARE_ZONE_ID'),
                        'httpRequestsAdaptiveGroupsStart' => $httpRequestsAdaptiveGroupsStart->toDateString(),
                        'httpRequests1dGroupsStart'       => $httpRequests1dGroupsStart->toDateString(),
                        'endDate'                         => Carbon::now()->subDay()->toDateString(),
                    ],
                ],
            ]
        );


        $response = json_decode($response->getBody()->getContents());

        foreach ($response->data->viewer->zones[0]->httpRequests1dGroups as $date) {
            $day = Day::firstOrNew(['date' => $date->dimensions->date]);
            $day->page_views = $date->sum->pageViews;
            $day->unique_visits = $date->uniq->uniques;
            $day->save();
        }

        foreach ($response->data->viewer->zones[0]->httpRequestsAdaptiveGroups as $date) {
            $day = Day::firstOrNew(['date' => $date->dimensions->date]);
            $day->visits = $date->sum->visits;
            $day->save();
        }


        $this->output->success('Done');
    }
}

