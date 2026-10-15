// Alpine for pages that are not Livewire pages (Livewire ships its own Alpine; never load
// both on one page). Pinned via npm instead of the alpinejs@3.x.x CDN build (task 4.2).
// Page scripts register components on 'alpine:init' before this module starts Alpine.
import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();
