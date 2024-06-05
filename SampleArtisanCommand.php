<?php

namespace App\Console\Commands\MigrationsV2;

use App\Models\Integration\IntegrationModel;
use Illuminate\Console\Command;

use App\Models\UserModel;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class ReplicateForBrands extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'replicate:For-brands {chunk=2000} {path=CommandData/replicate-command.json}';
    protected $availableRelations = ['integrations', 'tags', 'pixels', 'widgets', 'utms', 'domains', 'ips', 'apps'];

    protected $errosInfo;
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This is a single command that can be used to for replication that features hasmany relationship. it has two option one is chunk and other is path that is to store the error enteries and their relationships so that onyl those can be retried next time ';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(public int $startingIndex = 0)
    {
        $this->errosInfo =  collect();
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            $this->info("available options are:'Command', 'File'");
            HandleCoomand:
            $operation = $this->anticipate('What Feature you would like to use ?', ['Command', 'File']);
            switch ($operation) {
                case 'Command':
                    return $this->CommandHandle();
                case 'File':
                    return $this->FileHandle();
                default:
                    $this->info("Main n oper mazak kia tha ?");
                    abort(302);
            }
        } catch (Exception $e) {
            goto HandleCoomand;
        }
    }


    private function CommandHandle()
    {
        return rescue(function () {
            $this->info("Available Relationships are:");
            foreach ($this->availableRelations as $key => $value) {
                $this->info($value . ",");
            }
            if ($this->confirm('Do you wish to continue?')) {
                for ($x = 0; $x < count($this->availableRelations); $x++) {
                    $this->newLine();
                    $functionName =  $this->availableRelations[$x];
                    $userModel =  UserModel::has('brands')->has($functionName)
                        ->chunkById($this->argument('chunk'), function ($users) use ($functionName) {
                            $bar = $this->output->createProgressBar(count($users));
                            $bar->start();
                            $this->info('');
                            $this->info("starting from {$this->startingIndex} to " . count($users) + $this->startingIndex . "  ... 🙏");
                            $users->each(function ($user) use ($bar, $functionName) {
                                $this->startingIndex++;
                                $this->info("Replicating {$functionName} for User: {$user->getKey()}");
                                /* here we can increase the number of tries and ask user for retry */
                                $replicateObj = $user->{$functionName}->transform(function ($obj) {
                                    return    Arr::except($obj->toArray(), ['_id']);
                                })->toArray();

                                $user->{$functionName}()->delete();
                                try {
                                    $user->brands->each(function ($brand) use ($replicateObj, $functionName) {
                                        if (False && $functionName == 'integrations') {  //incase the observer makes issue remove that false

                                            IntegrationModel::withoutEvents(function () use ($brand, $replicateObj) {
                                                $brand->integrations()->createMany($replicateObj);
                                            });
                                        } else {
                                            $brand->{$functionName}()->createMany($replicateObj);
                                        }
                                    });
                                } catch (Exception $e) {
                                    // add failed function against user keys 
                                    $user->{$functionName}()->delete();  //clear the new data as we would try it later 
                                    $user->{$functionName}()->createMany($replicateObj);   // place the old data
                                    data_set($this->errosInfo, $user->getKey(), [...$this->errosInfo->get($user->getKey(), []), $functionName]);
                                }

                                $bar->advance();
                            });
                            $bar->finish();
                            if (filled($this->errosInfo)) {
                                foreach ($this->errosInfo as $key => $value) {
                                    $this->newLine();
                                    $this->error("Exception found for User: {$key} For " . implode(',', $this->errosInfo[$key]));
                                }
                                Storage::put($this->argument('path'), json_encode($this->errosInfo));
                            }
                        }, $column = '_id');
                }
            } else {
                $this->info("closing...");
            }
        }, function ($e) {
            $this->error("Exception Found in file :  " . __FILE__ . ", Method: " . __FUNCTION__ . ", Message:" . $e->getMessage() . ", Line:" . $e->getLine());
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
            $this->info("closing...");
        });
    }

    private function FileHandle()
    {
        return rescue(function () {
            $data =   Storage::get($this->argument('path'));
            $data =  collect(json_decode($data, true));
            if (blank($data)) {
                $this->info("no data found");
                return 0;
            } else {
                    $userIds= $data->keys()->all();
                    $userModel =  UserModel::has('brands')->whereIn("_id",$userIds)
                    ->chunkById($this->argument('chunk'), function ($users) use($data) {
                        $bar = $this->output->createProgressBar(count($users));
                        $bar->start();
                        $this->info('');
                        $this->info("starting from {$this->startingIndex} to " . count($users) + $this->startingIndex . "  ... 🙏");
                        $users->each(function ($user) use ($bar,$data){
                            $this->startingIndex++;
                            foreach ($data->get($user->getKey()) as $key => $functionName) {
                            $this->info("Replicating {$functionName} for User: {$user->getKey()}");
                            $replicateObj = $user->{$functionName}->transform(function ($obj) {
                                return    Arr::except($obj->toArray(), ['_id']);
                            })->toArray();

                            $user->{$functionName}()->delete();
                            try {
                                $user->brands->each(function ($brand) use ($replicateObj, $functionName) {
                                  
                                        $brand->{$functionName}()->createMany($replicateObj);
                                });
                            } catch (Exception $e) {
                                // add failed function against user keys 
                                $user->{$functionName}()->delete();  //clear the new data as we would try it later 
                                $user->{$functionName}()->createMany($replicateObj);   // place the old data
                                data_set($this->errosInfo, $user->getKey(), [...$this->errosInfo->get($user->getKey(), []), $functionName]);
                            }
                           }

                            $bar->advance();
                        });
                        $bar->finish();
                        if (filled($this->errosInfo)) {
                            foreach ($this->errosInfo as $key => $value) {
                                $this->newLine();
                                $this->error("Exception found for User: {$key} For " . implode(',', $this->errosInfo[$key]));
                            }
                            Storage::put($this->argument('path'), json_encode($this->errosInfo));
                        }
                    }, $column = '_id');
               
                    $this->info("done");
            }
        }, function ($e) {
            $this->error("Exception Found in file :  " . __FILE__ . ", Method: " . __FUNCTION__ . ", Message:" . $e->getMessage() . ", Line:" . $e->getLine());
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
            $this->info("closing...");
        });
    }
}
