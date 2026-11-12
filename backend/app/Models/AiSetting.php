<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AiSetting extends Model
{
    protected $fillable = ['id', 'provider', 'model', 'api_key', 'enabled', 'limits', 'version', 'endpoint', 'strict_json', 'free_only'];
    protected $hidden = ['api_key'];
    protected function casts(): array { return ['api_key' => 'encrypted', 'enabled' => 'boolean', 'limits' => 'array', 'version' => 'integer', 'strict_json' => 'boolean', 'free_only' => 'boolean']; }
    public static function current(string $provider = 'openrouter'): self
    {
        $id = ['openrouter' => 1, 'gemini' => 2, 'ollama' => 3, 'huggingface' => 4][$provider] ?? abort(422, 'Unknown AI provider.');
        return static::firstOrCreate(['id' => $id], ['provider' => $provider, 'model' => '', 'enabled' => false, 'limits' => config('generation.limits')])->fresh();
    }
    public function key(): string { return $this->api_key ?: (config('generation.keys.'.$this->provider) ?: ''); }
}
