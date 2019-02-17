@extends('default')
@section('content')
    <h1>{{ $title }} Recruitment Ad</h1>
    @if($title == 'Group')
    <form onsubmit="return saveAd(this);" id="corpAdForm">
    @else
    <form onsubmit="return saveAd(this);" id="corpAdForm">
        <input type="hidden" id="corpId" value="{{ $corp_id }}" />
    @endif
        <input type="hidden" value="{{ $ad->id }}" id="ad_id" name="ad_id" />
        <input type="hidden" id="_token" name="_token" value="{{ csrf_token() }}" />
    @if($title == 'Group')
        <div class="form-group">
            <label for="name">Group Name</label>
            <input type="text" class="form-control" id="name" name="name" placeholder="Name..." value="{{ $ad->group_name }}" />
        </div>
    @endif
        <div class="form-group">
            <label for="slug">Page Slug</label>
            <input type="text" class="form-control" id="slug" name="slug" placeholder="Slug..." value="{{ $ad->slug }}" />
        </div>
        <div class="form-group">
            <label for="text">Ad Text</label>
            <textarea class="form-control" rows="10" id="text" name="text" placeholder="Ad Text...">{{ $ad->text }}</textarea>
            <small id="textInfo" class="form-text">Markdown is supported.</small>
        </div>
        <div class="form-group">
            <div class="row">
                <div class="col-4">
                    <label>Form Questions</label>
                    <div class="questions">
                        @foreach($questions as $question)
                            <div class="form-group">
                                <input type="text" class="form-control" value="{{ $question->question }}" name="questions[{{ $question->id }}][]" id="questions[{{ $question->id }}][]"/>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" class="btn btn-success" onClick="addQuestion();"><span class="fa fa-plus"></span></button>
                </div>
                <div class="col-4">
                    <label>Application Requirements</label>
                    <div class="requirements">
                        @foreach($requirements as $requirement)
                            {!! $requirement !!}
                        @endforeach
                    </div>
                    <button type="button" class="btn btn-success" onClick="addRequirement();"><span class="fa fa-plus"></span></button>
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Submit</button>
    </form>
@endsection
@section('scripts')
    <script type="text/javascript">
        let counter = $(".questions").children().length + 1;
        let requirement = null;

        $.get('/api/requirements/template', (e) => requirement = e);

        function addQuestion() {
            let toAppend = "<div class='form-group'> \
                <input type='text' class='form-control' placeholder='Question " + counter + "' name='questions[0][]' id='questions[0][]'> \
            </div>";
            $(".questions").append(toAppend);
            counter++;
        }

        function addRequirement()
        {
            $(".requirements").append(requirement);
        }

        function saveAd(f)
        {
           f = $(f).serializeObject();
           let c = $("#corpId").val();
           let url = (c !== undefined) ? '/corporations/' + c + '/ad/save' : '/group/ad/save';

           $.post(url, f, function(e) {
                e = JSON.parse(e);

                if (e.success === false)
                    showError(e.message);
                else
                {
                    if (c !== undefined)
                        setTimeout(() => location.reload(), 2000);
                    else
                        setTimeout(() => window.location.href = '/group/ad/' + e.data, 2000);

                    showInfo(e.message + ". Page will reload in a few seconds");
                }
           });

            return false;
        }
    </script>
@endsection