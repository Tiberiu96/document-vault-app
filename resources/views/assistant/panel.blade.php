<div class="assistant-widget" data-assistant data-endpoint="{{ route('assistant.stream') }}" data-csrf="{{ csrf_token() }}"
    data-thinking="{{ __('assistant.thinking') }}" data-working="{{ __('assistant.working') }}"
    data-list-status="{{ __('assistant.list_status') }}" data-document-status="{{ __('assistant.document_status') }}"
    data-reminder-status="{{ __('assistant.reminder_status') }}" data-failed="{{ __('assistant.failed') }}">
    <button class="assistant-launcher" type="button" data-assistant-open aria-controls="assistant-panel" aria-expanded="false" aria-label="{{ __('assistant.open') }}" title="{{ __('assistant.open') }}">
        <x-icon name="sparkle" :size="24"/><span>{{ __('assistant.title') }}</span>
    </button>
    <dialog id="assistant-panel" class="assistant-panel" aria-labelledby="assistant-title">
        <header class="assistant-header">
            <span class="assistant-mark"><x-icon name="sparkle" :size="22"/></span>
            <div><h2 id="assistant-title">{{ __('assistant.title') }}</h2><p>{{ $workspace->name }}</p></div>
            <button type="button" class="assistant-icon-button" data-assistant-close aria-label="{{ __('assistant.close') }}" title="{{ __('assistant.close') }}"><x-icon name="close" :size="24"/></button>
        </header>
        <div class="assistant-content" data-assistant-scroll>
        <div class="assistant-scope"><x-icon name="document"/><span>{{ __('assistant.all_documents') }}</span></div>
        <p class="assistant-scope-note">{{ __('assistant.sources_note') }}</p>
        <div class="assistant-messages" data-assistant-messages aria-live="polite" aria-relevant="additions text">
            <div class="assistant-welcome" data-assistant-welcome>
                <span class="assistant-mark"><x-icon name="sparkle"/></span>
                <p>{{ __('assistant.welcome') }}</p>
            </div>
        </div>
        <div class="assistant-suggestions" data-assistant-suggestions>
            @foreach(__('assistant.suggestions') as $suggestion)<button type="button" data-assistant-suggestion>{{ $suggestion }}</button>@endforeach
        </div>
        <div class="assistant-status" data-assistant-status hidden role="status"></div>
        </div>
        <div class="assistant-footer">
        <form class="assistant-composer" data-assistant-form>
            <label class="sr-only" for="assistant-message">{{ __('assistant.placeholder') }}</label>
            <textarea id="assistant-message" name="message" maxlength="{{ config('assistant.max_message_characters') }}" rows="2" placeholder="{{ __('assistant.placeholder') }}" required></textarea>
            <button type="submit" class="assistant-send" aria-label="{{ __('assistant.send') }}" title="{{ __('assistant.send') }}"><x-icon name="arrow" :size="22"/></button>
            <button type="button" class="assistant-stop" data-assistant-stop hidden>{{ __('assistant.stop') }}</button>
        </form>
        <p class="assistant-helper">{{ __('assistant.helper') }}</p>
        </div>
    </dialog>
</div>
