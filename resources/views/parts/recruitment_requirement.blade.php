<div class="form-group">
    <select name="requirements[{{ $requirements->id }}][]" class="custom-select" autocomplete="off">
    @foreach($requirements as $requirement)
        @if (gettype($requirement) != "object")
            @continue
        @endif
        @if (!empty($selected) && $selected == "$requirement->id-$requirement->type")
            <option value="{{ $requirement->id }}-{{ $requirement->type }}" selected>
        @else
            <option value="{{ $requirement->id }}-{{ $requirement->type }}">
        @endif
        @switch($requirement->type)
            @case(\App\Models\RecruitmentRequirement::CORPORATION)
                Corporation: {{ $requirement->name }}
                @break

            @case(\App\Models\RecruitmentRequirement::CORE_GROUP)
                Core Group: {{ $requirement->name }}
                @break

            @case(\App\Models\RecruitmentRequirement::ALLIANCE)
                Alliance: {{ $requirement->name }}
                @break

            @default
                UNKNOWN TYPE
                @break
        @endswitch
        </option>
    @endforeach
    </select>
</div>