@extends('default')
@section('content')
@include('parts/application/character_header', ['character' => $character])
<div class="row justify-content-center">
@if(isset($application))
    <div class="modal fade" id="state-modal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content bg-dark text-white">
                <div class="modal-header">
                    <h5 class="modal-title">Application state information</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>{!! $state_tooltip !!}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-3 col-md-5">
        <select style="width: 95%;" class="custom-select" style="width: 15%; margin-top: 1em;" autocomplete="off" onchange="updateStatus(this);">
    @foreach($states as $id => $state)
        @if($application->status == $id)
            <option value="{{ $id }}" selected>{{ $state }}</option>
        @else
            <option value="{{ $id }}">{{ $state }}</option>
        @endif
    @endforeach
        </select>
        <span style="cursor: pointer;" class="fa fa-info-circle" data-toggle="modal" data-target="#state-modal"></span>
    </div>
@endif
</div><br />
<div class="row justify-content-center">
    <div class="col-12">
        <ul class="nav nav-pills justify-content-center">
            <li class="nav-item dropdown ml-2">
                <a class="nav-link dropdown-toggle" data-toggle="dropdown" href="#"
                   role="button" aria-haspopup="true" aria-expanded="false">
                    Applications
                </a>
                <div class="dropdown-menu">
                    @foreach($userApplications as $userApplication)
                        <a class="dropdown-item" target="_blank" href="/application/{{ $userApplication->id }}">
                            {{ $userApplication->recruitmentAd->group_name }}
                        </a>
                    @endforeach
                </div>
            </li>
        @if($sp != null)
         @if(isset($application))
            <li class="nav-item ml-2">
                <a class="nav-link active show" id="application-tab" data-toggle="pill" href="#tab-application" role="tab" aria-controls="tab-application" aria-selected="true">
                    Application
                </a>
            </li>
            <li class="nav-item ml-2">
                <a class="nav-link" id="overview-tab" data-toggle="pill" href="#tab-overview" role="tab" aria-controls="tab-overview" aria-selected="false">
                    Overview
                    <span id="result-tab-overview" style="color: red;" class="fas"></span>
                </a>
            </li>
         @else
            <li class="nav-item ml-2">
                <a class="nav-link active show" id="overview-tab" data-toggle="pill" href="#tab-overview" role="tab" aria-controls="tab-overview" aria-selected="true">
                    Overview
                </a>
            </li>
         @endif
             <li class="nav-item ml-2">
                 <a class="nav-link" id="skills-tab" data-toggle="pill" href="#tab-skills" role="tab" aria-controls="tab-skills" aria-selected="false">
                     Skills
                     <span id="result-tab-skills" style="color: red;" class="fas"></span>
                 </a>
             </li>
             <li class="nav-item ml-2">
                 <a class="nav-link" id="mail-tab" data-toggle="pill" href="#tab-mail" role="tab" aria-controls="tab-mail" aria-selected="false">
                     Mail
                     <span id="result-tab-mail" style="color: red;" class="fas"></span>
                 </a>
             </li>
            <li class="nav-item ml-2">
                <a class="nav-link" id="assets-tab" data-toggle="pill" href="#tab-assets" role="tab" aria-controls="tab-assets" aria-selected="false">
                    Assets &amp; Journal
                    <span id="result-tab-assets" style="color: red;" class="fas"></span>
                </a>
            </li>
            <li class="nav-item ml-2">
                <a class="nav-link" id="market-tab" data-toggle="pill" href="#tab-market" role="tab" aria-controls="tab-market" aria-selected="false">
                    Market
                    <span id="result-tab-market" style="color: red;" class="fas"></span>
                </a>
            </li>
             <li class="nav-item ml-2">
                 <a class="nav-link" id="contracts-tab" data-toggle="pill" href="#tab-contracts" role="tab" aria-controls="tab-contracts" aria-selected="false">
                     Contracts
                     <span id="result-tab-contracts" style="color: red;" class="fas"></span>
                 </a>
             </li>
             <li class="nav-item ml-2">
                 <a class="nav-link" id="notifications-tab" data-toggle="pill" href="#tab-notifications" role="tab" aria-controls="tab-notifications" aria-selected="false">
                     Notifications
                     <span id="result-tab-notifications" style="color: red;" class="fas"></span>
                 </a>
             </li>
             <li class="nav-item ml-2">
                 <a class="nav-link" id="utilities-tab" data-toggle="pill" href="#tab-utilities" role="tab" aria-controls="tab-utilities" aria-selected="false">
                     Utilities
                 </a>
             </li>
        @endif
        </ul>
    </div>
</div><br />
<div id="errors"></div><br />
@if($sp != null)
<div class="row justify-content-center">
    <div class="col-auto">
        <button type="button" class="btn btn-primary" id="load-esi-button" onclick="loadEsiData();">Load ESI Data</button>
    </div>
</div>
@endif
<hr class="my-4">
<div class="tab-content" id="tab-content">
@if(isset($application))
    @include('parts/application/application', ['questions' => $application->questions(),
                                               'changelog' => $application->changelog,
                                               'comments' => $application->comments])
    <div class="tab-pane fade" id="tab-overview" role="tabpanel" aria-labelledby="tab-overview"></div>
@elseif($sp != null)
    <div class="tab-pane fade show active" id="tab-overview" role="tabpanel" aria-labelledby="tab-overview">
        @include('parts/application/overview')
    </div>
@endif
    <div class="tab-pane fade" id="tab-skills" role="tabpanel" aria-labelledby="tab-skills"></div>
    <div class="tab-pane fade" id="tab-mail" role="tabpanel" aria-labelledby="tab-mail"></div>
    <div class="tab-pane fade" id="tab-assets" role="tabpanel" aria-labelledby="tab-assets"></div>
    <div class="tab-pane fade" id="tab-market" role="tabpanel" aria-labelledby="tab-market"></div>
    <div class="tab-pane fade" id="tab-contracts" role="tabpanel" aria-labelledby="tab-contracts"></div>
    <div class="tab-pane fade" id="tab-notifications" role="tabpanel" aria-labelledby="tab-notifications"></div>
    <div class="tab-pane fade" id="tab-utilities" role="tabpanel" aria-labelledby="tab-utilities">@include('parts/application/utilities')</div>
</div>
@endsection
@section('styles')
    <style>
        .nav-pills .nav-link {
            background-image: -webkit-gradient(linear, left top, left bottom, from(#484e55), color-stop(60%, #3A3F44), to(#313539));
            background-image: linear-gradient(#484e55, #3A3F44 60%, #313539);
            background-repeat: no-repeat;
            -webkit-filter: none;
            filter: none;
            border: 1px solid rgba(0,0,0,0.6);
            text-shadow: 1px 1px 1px rgba(0,0,0,0.3);
            color: #fff;
        }

        .nav-pills .nav-link.active, .nav-pills .nav-link:hover {
            background-color: transparent;
            background-image: -webkit-gradient(linear, left top, left bottom, from(#101112), color-stop(40%, #17191b), to(#1b1e20));
            background-image: linear-gradient(#101112, #17191b 40%, #1b1e20);
            background-repeat: no-repeat;
            -webkit-filter: none;
            filter: none;
            border: 1px solid rgba(0, 0, 0, 0.6);
        }
    </style>
@endsection
@section('scripts')
    <script type="text/javascript">
        document.title = "{{ $character->name }} - " + document.title;
        let esiLoaded = false;
        let char_id = "{{ $character->character_id }}";

        $(function() {
        @if(isset($application))
            // Load warnings on document load
            let warnings_div = $('#warnings');

            warnings_div.html('<div class="list-group-item bg-dark text-white">Loading...</div>');
            $.get('/application/{{ $application->id }}/warnings', (e) => warnings_div.html(e));
        @else
            // This needs to be done since, for individual character ESI viewing, overview is not loaded as a partial,
            // and therefore this is never ran
            $('[data-toggle="tooltip"]').tooltip();
        @endif
        });

        function loadPartial(url, anchor, name, additionalFunction = null)
        {
            let res = $("#result-" + anchor);
            let errors = $("#errors");

            res.html('<img width="22" src="/img/loading.webp" />');

            $.ajax({
                url: url,
                type: 'GET',
                success: function(e) {
                    res.empty();
                    e = JSON.parse(e);

                    if (e.success === true)
                    {
                        $("#" + anchor).html(e.message);
                        res.attr('style', 'color: green;');
                        res.addClass('fa-check');

                        if (additionalFunction)
                            additionalFunction();
                    }
                    else
                    {
                        res.addClass('fa-times');
                    }
                },
                error: function(e) {
                    res.empty();
                    errors.append('<div class="row justify-content-center">Loading of ' + name + ' failed:</div><div class="row justify-content-center"><pre style="color: white;"><xmp>' + e.responseJSON.message.split("response")[0] + '</xmp></pre></div>');
                    res.addClass('fa-times');
                }
            });
        }

        function loadEsiData() {
            if (esiLoaded)
            {
                showError('ESI already loaded. To see updated ESI, reload the page and click the button again.');
                return;
            }

            esiLoaded = true;

            @if(isset($application))
                loadPartial('/api/esi/' + char_id + '/overview', 'tab-overview', 'overview', () =>  $('[data-toggle="tooltip"]').tooltip());
            @endif
            loadPartial('/api/esi/' + char_id + '/skills', "tab-skills", 'skills');
            loadPartial('/api/esi/' + char_id + '/mail', "tab-mail", 'mail');
            loadPartial('/api/esi/' + char_id + '/assets_journal', "tab-assets", 'assets', () => $("#journal-table").DataTable({"order": [[0, "desc"]], "paging": false}));
            loadPartial('/api/esi/' + char_id + '/market', "tab-market", 'market');
            loadPartial('/api/esi/' + char_id + '/contracts', "tab-contracts", 'contracts');
            loadPartial('/api/esi/' + char_id + '/notifications', "tab-notifications", 'notifications', () => $("#notifications-table").DataTable({"order": [[0, "desc"]], "paging": false}));
        }

    @if(isset($application))
        let comment_button = $('#add_comment_btn');
        let new_question_textarea = $('#new_question');
        let box_open = false;
        let application_id = "{{ $application->id }}";

        function updateStatus(f)
        {
            let data = {
                _token: "{{ csrf_token() }}",
                state: f.value,
            };

            $.post('/application/' + application_id + '/state/update', data, function (e) {
                e = JSON.parse(e);

                if (e.success === true)
                    showInfo(e.message);
                else
                    showError(e.message);
            });
        }

        function deleteComment(id)
        {
            if (!confirm("Are you sure you wish to delete this comment?"))
                return;

            let data = {
                _token: "{{ csrf_token() }}",
                comment_id: id
            };

            $.post('/application/' + application_id + '/comments/delete', data, function(e) {
                e = JSON.parse(e);

                if (e.success === true)
                {
                    showInfo(e.message);
                    $("#comment_" + id).remove();
                }
                else
                    showError(e.message);
            });
        }

        function handleCommentButtonClick()
        {
            if (box_open === false)
            {
                new_question_textarea.show();
                comment_button.text('Save');
                box_open = true;
            }
            else
            {
                let data = {
                    _token: "{{ csrf_token() }}",
                    comment: new_question_textarea.val()
                };

                $.post('/application/' + application_id + '/comments/add', data, function (e) {
                    e = JSON.parse(e);

                    if (e.success === true)
                    {
                        new_question_textarea.val('');
                        new_question_textarea.hide();
                        comment_button.text('Add');
                        box_open = false;
                        $("#comments").append(e.message);
                        showInfo('Comment saved');
                    }
                    else
                        showError(e.message);
                });
            }
        }
    @endif

    function checkFit(f)
    {
        let fit = f[0].value;
        if (!fit)
            return false;

        let data = {
            _token: "{{ csrf_token() }}",
            fit: fit
        };

        $.post('/api/esi/' + char_id + '/fit_check', data, function (e) {
            e = JSON.parse(e);
            $(".checker-output").text(e.message);
        });

        return false;
    }

        function checkSkillplan(f)
        {
            let skillplan = f[0].value;
            if (!skillplan)
                return false;

            let data = {
                _token: "{{ csrf_token() }}",
                skillplan: skillplan
            };

            $.post('/api/esi/' + char_id + '/skillplan_check', data, function (e) {
                e = JSON.parse(e);

                if (e.success === true)
                {
                    let skills = e.message;
                    if (skills.length > 0)
                        $(".checker-output").html('Missing skills:<pre class="text-white">' + skills.join('\n') + '</pre>', 10000);
                    else
                        $(".checker-output").text('Skill requirements met');
                }
                else
                    $(".checker-output").text(e.message);
            });

            return false;
        }

        function toggleCollapse(anchor, id)
        {
            if ($(anchor).is(':empty'))
            {
                $(anchor).collapse('toggle');
                $(anchor).html('Loading...');
                $.get('/mail/' + char_id + '/' + id, (e) => $(anchor).html(e));
            }
            else
                $(anchor).collapse('toggle');
        }
    </script>
@endsection
