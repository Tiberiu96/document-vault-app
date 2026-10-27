<?php

namespace App\Console\Commands;

use App\Contracts\Ai\AiProviderInterface;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\LocalAiProbe;
use Illuminate\Console\Command;

class CheckAi extends Command
{
    protected $signature = 'vault:ai:check {--infer : Ruleaza o proba fictiva, fara documente private}';

    protected $description = 'Verifica providerul AI configurat, fara afisarea datelor private';

    public function handle(AiProviderInterface $provider, LocalAiProbe $probe): int
    {
        try {
            $health = $provider->health();
            $this->info('Ollama raspunde. Versiune: '.$health['version']);
            $this->line('Model: '.$health['model'].'; instalat: '.($health['installed'] ? 'da' : 'nu'));
            if (! $health['installed']) {
                return self::FAILURE;
            }
            if ($this->option('infer')) {
                $reply = $probe->run('Spune pe scurt ce este un document PDF.', 'ro');
                $this->info('Inferenta locala reusita; JSON validat. Lungime raspuns: '.mb_strlen($reply).' caractere.');
            }

            return self::SUCCESS;
        } catch (AiProviderException $exception) {
            $this->error('Verificarea AI a esuat: '.$exception->reason.'. Nu sunt afisate prompturi sau raspunsuri private.');

            return self::FAILURE;
        }
    }
}
