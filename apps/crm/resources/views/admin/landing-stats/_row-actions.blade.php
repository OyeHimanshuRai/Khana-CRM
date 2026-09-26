{{--
    The three buttons every landing-page row carries (§19): edit, show/hide,
    delete.

    Shared by all five modules, which is why it takes its route and permission
    prefixes as parameters rather than naming a module. It lives under
    `landing-stats` only because a partial has to live somewhere; nothing about
    it is specific to a statistic.

    @param  \Illuminate\Database\Eloquent\Model  $row
    @param  string  $routeBase   e.g. "admin.testimonials"
    @param  string  $can         e.g. "content.testimonials"
    @param  string  $label       what to name in the delete confirmation
    @param  string  $modalTitle
--}}

<div class="row-actions">
    @allows($can.'.edit')
        <a class="btn btn-icon" href="{{ route($routeBase.'.edit', $row->id) }}"
           data-modal="{{ route($routeBase.'.edit', $row->id) }}"
           data-modal-title="{{ $modalTitle }}"
           data-modal-size="lg"
           aria-label="Edit {{ $label }}">
            <x-icon name="edit" :size="15" />
        </a>

        {{--
            Show/hide rather than delete is the normal way to take something
            off the page: a testimonial from a customer who has since left is
            not a mistake to erase.
        --}}
        <form method="POST" action="{{ route($routeBase.'.status', $row->id) }}" data-ajax data-refresh-list>
            @csrf
            @method('PUT')
            <button type="submit" class="btn btn-icon"
                    aria-label="{{ $row->is_active ? 'Hide' : 'Show' }} {{ $label }}"
                    title="{{ $row->is_active ? 'Hide from the page' : 'Show on the page' }}">
                <x-icon name="{{ $row->is_active ? 'user-x' : 'user-check' }}" :size="15" />
            </button>
        </form>
    @endallows

    @allows($can.'.delete')
        <form method="POST" action="{{ route($routeBase.'.destroy', $row->id) }}"
              data-ajax data-refresh-list
              onsubmit="return confirm('Delete {{ addslashes($label) }}? Hiding it keeps it for later.')">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-icon is-danger" aria-label="Delete {{ $label }}">
                <x-icon name="trash" :size="15" />
            </button>
        </form>
    @endallows
</div>
