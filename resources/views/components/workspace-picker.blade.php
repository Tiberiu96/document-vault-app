@props(['workspace', 'workspaces'])
<details {{ $attributes->class(['workspace-picker']) }}>
    <summary><span class="workspace-picker-label"><span>{{ __('documents.workspace') }}</span><strong>{{ $workspace->name }}</strong></span><x-icon name="chevron" :size="18"/></summary>
    <div class="workspace-options">@foreach($workspaces as $option)<form action="{{ route('workspace.switch') }}" method="POST">@csrf<input type="hidden" name="workspace_id" value="{{ $option->id }}"><button type="submit" @if($workspace->id === $option->id) aria-current="true" @endif><span>{{ $option->name }}</span>@if($workspace->id === $option->id)<x-icon name="check" :size="18"/>@endif</button></form>@endforeach</div>
</details>
