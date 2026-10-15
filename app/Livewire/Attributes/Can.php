<?php

namespace App\Livewire\Attributes;

use App\Auth\StaffAuthorization;
use Attribute;
use Livewire\Features\SupportAttributes\Attribute as LivewireAttribute;
use ReflectionMethod;

/**
 * #[Can('perm|perm')] on a public Livewire action: the caller must be staff holding one
 * of the permissions, checked before the action runs; a denial is logged and returns 403.
 *
 * Admin components must declare it on every public action. An undeclared action is
 * refused (fail closed) by the guard registered in AppServiceProvider.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Can extends LivewireAttribute
{
    public function __construct(public string $permissions) {}

    public function call(array $params): void
    {
        StaffAuthorization::ensure($this->permissions, [
            'component' => $this->component::class,
            'action' => $this->getName(),
        ]);
    }

    /**
     * True for methods that come from Livewire's own traits (e.g. WithFileUploads'
     * _startUpload/_finishUpload). They are framework plumbing, not app actions; the page
     * route and the app action that uses their result are permission-checked.
     */
    public static function isFrameworkMethod(object $component, string $method): bool
    {
        foreach (class_uses_recursive($component) as $trait) {
            if (str_starts_with($trait, 'Livewire\\') && method_exists($trait, $method)) {
                return true;
            }
        }

        return false;
    }

    /** True when the component's public method carries a #[Can] declaration. */
    public static function declaredOn(object $component, string $method): bool
    {
        if (! method_exists($component, $method)) {
            return false;
        }

        return (new ReflectionMethod($component, $method))->getAttributes(self::class) !== [];
    }
}
