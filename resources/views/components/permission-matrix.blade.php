@props([
    /** Collection from App\Support\PermissionRegistry::tree() */
    'tree',
    /** Permission names that are checked. @var array<int, string> */
    'granted' => [],
    /**
     * Permission names already supplied by the subject's roles. Rendered as
     * read-only context on the user screen so an operator can see what a
     * direct grant would add on top of. @var array<int, string>
     */
    'inherited' => [],
    /** Render every input disabled (used for the Super Admin role). */
    'locked' => false,
])

@php
    $granted = collect($granted);
    $inherited = collect($inherited);
    $total = $tree->sum('count');
@endphp

<div class="pmatrix" data-permission-matrix data-total="{{ $total }}">

    {{-- Always present so an all-unchecked save still submits the key and
         the server can tell "none selected" from "field not in this form". --}}
    <input type="hidden" name="permissions[]" value="">

    <div class="pmatrix-toolbar">
        <div class="pmatrix-search">
            <x-icon name="search" :size="15" />
            <label for="pm-search" class="sr-only">Search permissions</label>
            <input id="pm-search" type="search" data-pm-search autocomplete="off"
                   placeholder="Search modules, sub-modules or actions…">
        </div>

        <div class="pmatrix-toolbar-actions">
            <span class="badge badge-brand" data-pm-count>0 / {{ $total }}</span>

            @unless ($locked)
                <button type="button" class="btn btn-sm" data-pm-select-all>Select all</button>
                <button type="button" class="btn btn-sm" data-pm-clear-all>Clear all</button>
            @endunless
        </div>
    </div>

    @if ($locked)
        <div class="alert alert-info">
            This role has unrestricted access by design and cannot be edited.
            Its permissions are granted implicitly rather than stored.
        </div>
    @endif

    <div class="pmatrix-modules">
        @foreach ($tree as $module)
            <section class="pmatrix-module" data-pm-module>
                <header class="pmatrix-module-head">
                    <label class="check pmatrix-module-label">
                        <input type="checkbox" data-pm-module-all @disabled($locked)>
                        <x-icon :name="$module['icon']" :size="16" />
                        <span>{{ $module['label'] }}</span>
                    </label>

                    <span class="text-xs text-muted" data-pm-module-count>0 / {{ $module['count'] }}</span>
                </header>

                <div class="pmatrix-rows">
                    @foreach ($module['submodules'] as $sub)
                        @php
                            // Everything the search box should match against.
                            $haystack = Str::lower(
                                $module['label'].' '.$sub['label'].' '
                                .$sub['actions']->pluck('label')->implode(' ').' '
                                .$sub['names']->implode(' ')
                            );
                        @endphp

                        <div class="pmatrix-row" data-pm-row data-haystack="{{ $haystack }}">
                            <div class="pmatrix-row-head">
                                <label class="check">
                                    <input type="checkbox" data-pm-row-all @disabled($locked)>
                                    <span>{{ $sub['label'] }}</span>
                                </label>
                            </div>

                            <div class="pmatrix-chips">
                                @foreach ($sub['actions'] as $action)
                                    @php
                                        $isInherited = $inherited->contains($action['name']);
                                    @endphp

                                    <label
                                        class="pmchip tone-{{ $action['tone'] }} {{ $isInherited ? 'is-inherited' : '' }}"
                                        @if ($isInherited) title="Already granted by an assigned role" @endif
                                    >
                                        <input
                                            type="checkbox"
                                            name="permissions[]"
                                            value="{{ $action['name'] }}"
                                            data-pm-item
                                            @checked($granted->contains($action['name']))
                                            @disabled($locked)
                                        >
                                        <span>{{ $action['label'] }}</span>
                                        @if ($isInherited)
                                            <span class="pmchip-flag" aria-label="from role">R</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    <div class="pmatrix-empty" data-pm-empty hidden>
        <x-icon name="search" :size="26" />
        <h3>No matching permissions</h3>
        <p class="text-sm text-muted">Try a different search term.</p>
    </div>
</div>
