<div class="max-w-5xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 mb-1">Staff</h1>
    <p class="text-sm text-gray-500 mb-6">Invite people to the admin area, choose what they can do, and switch off access when they leave.</p>

    @if ($notice)
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm">{{ $notice }}</div>
    @endif
    @error('staff')
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">{{ $message }}</div>
    @enderror

    {{-- Invite --}}
    <section class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <h2 class="font-semibold text-gray-800 mb-3">Invite a staff member</h2>
        <form wire:submit="invite" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-start">
            <div>
                <label for="invite-name" class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                <input id="invite-name" type="text" wire:model="name" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" autocomplete="off">
                @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="invite-email" class="block text-xs font-medium text-gray-600 mb-1">Email</label>
                <input id="invite-email" type="email" wire:model="email" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" autocomplete="off">
                @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="invite-role" class="block text-xs font-medium text-gray-600 mb-1">Role</label>
                <select id="invite-role" wire:model="role" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white">
                    <option value="">Choose a role…</option>
                    @foreach ($roles as $r)
                        <option value="{{ $r }}">{{ $r }}</option>
                    @endforeach
                </select>
                @error('role') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="md:pt-5">
                <button type="submit" wire:loading.attr="disabled"
                        class="w-full bg-brand-500 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-600 transition">
                    Send invitation
                </button>
            </div>
        </form>
        <p class="text-xs text-gray-400 mt-3">They get an email with a link to set their password (valid for 7 days). An existing customer account simply gains the role.</p>
    </section>

    {{-- Staff list --}}
    <section class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-5 py-3 font-medium">Person</th>
                    <th class="px-5 py-3 font-medium">Role</th>
                    <th class="px-5 py-3 font-medium">Status</th>
                    <th class="px-5 py-3 font-medium text-right">Access</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($staff as $member)
                    @php($isMe = $member->is(auth()->user()))
                    <tr wire:key="staff-{{ $member->id }}" class="{{ $member->isDeactivated() ? 'bg-gray-50 text-gray-400' : '' }}">
                        <td class="px-5 py-3">
                            <div class="font-medium {{ $member->isDeactivated() ? '' : 'text-gray-900' }}">{{ $member->name }}{{ $isMe ? ' (you)' : '' }}</div>
                            <div class="text-xs text-gray-500">{{ $member->email }}</div>
                        </td>
                        <td class="px-5 py-3">
                            @if ($isMe)
                                <span>{{ $member->getRoleNames()->first() }}</span>
                            @else
                                <label class="sr-only" for="role-{{ $member->id }}">Role for {{ $member->name }}</label>
                                <select id="role-{{ $member->id }}"
                                        wire:change="changeRole({{ $member->id }}, $event.target.value)"
                                        class="border border-gray-300 rounded-lg px-2 py-1 text-sm bg-white">
                                    @foreach ($roles as $r)
                                        <option value="{{ $r }}" @selected($member->hasRole($r))>{{ $r }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            @if ($member->isDeactivated())
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs bg-gray-200 text-gray-600">Deactivated</span>
                            @elseif ($member->isPendingInvite())
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">Invited</span>
                            @else
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700">Active</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right whitespace-nowrap">
                            @unless ($isMe)
                                @if ($member->isPendingInvite() && ! $member->isDeactivated())
                                    <button wire:click="resendInvite({{ $member->id }})" class="text-xs text-gray-600 hover:text-gray-900 px-2 py-1">Resend invite</button>
                                @endif
                                @if ($member->isDeactivated())
                                    <button wire:click="reactivate({{ $member->id }})" class="text-xs text-green-700 hover:text-green-900 px-2 py-1">Reactivate</button>
                                @else
                                    <button wire:click="deactivate({{ $member->id }})"
                                            wire:confirm="Deactivate {{ $member->name }}? They will be signed out and cannot sign in until reactivated."
                                            class="text-xs text-red-600 hover:text-red-800 px-2 py-1">Deactivate</button>
                                @endif
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
