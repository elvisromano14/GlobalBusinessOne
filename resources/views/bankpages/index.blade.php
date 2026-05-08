@extends('adminlte::page')
@section('title', 'Limpieza de Extractos Bancarios')
@section('favicons')
    <link rel="icon" href="{{ asset('vendor/adminlte/dist/img/icon.jpg') }}?v=3" type="image/jpeg" />
@stop

@section('content_header')
    <h1>Gestión de Extractos Bancarios (OBNK)</h1>
@stop

@section('content')
<div class="container-fluid">

    {{-- Filtros --}}
    <div class="card card-primary card-outline">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-filter mr-1"></i> Filtros</h3></div>
        <form action="{{ route('bankpages.index') }}" method="GET" class="card-body pb-1">
            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Nombre de la Cuenta</label>
                    <input type="text" name="account_name" class="form-control" value="{{ request('account_name') }}" placeholder="Nombre de cuenta">
                </div>
                <div class="col-md-6 form-group">
                    <label>Código de Cuenta</label>
                    <input type="text" name="account_code" class="form-control" value="{{ request('account_code') }}" placeholder="AcctCode">
                </div>
            </div>
            <div class="row">
                <div class="col-md-3 form-group">
                    <label>Fecha Desde</label>
                    <input type="date" name="date_from" class="form-control" value="{{ request('date_from', date('Y-m-01')) }}">
                </div>
                <div class="col-md-3 form-group">
                    <label>Fecha Hasta</label>
                    <input type="date" name="date_to" class="form-control" value="{{ request('date_to', date('Y-m-d')) }}">
                </div>
                <div class="col-md-6 d-flex align-items-end justify-content-end mb-3">
                    <button type="submit" class="btn btn-primary mr-2"><i class="fas fa-search mr-1"></i> Consultar</button>
                    <a href="{{ route('bankpages.index') }}" class="btn btn-secondary"><i class="fas fa-undo mr-1"></i> Limpiar</a>
                </div>
            </div>
        </form>
    </div>

    {{-- Alertas --}}
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    @if(isset($error) && $error)
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-1"></i> {{ $error }}</div>
    @endif

    {{-- Panel de progreso de limpieza --}}
    <div id="cleanup-progress-card" class="card card-warning card-outline {{ session('cleanup_started') ? '' : 'd-none' }}">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-cog fa-spin mr-1"></i> Limpieza en Progreso</h3></div>
        <div class="card-body">
            <p class="mb-1">Registros borrados: <strong id="prog-deleted">0</strong> &nbsp;|&nbsp; Fallidos: <strong id="prog-failed">0</strong></p>
            <div class="progress">
                <div id="prog-bar" class="progress-bar bg-warning progress-bar-striped progress-bar-animated" style="width:100%">Procesando...</div>
            </div>
            <small class="text-muted">El proceso continúa en segundo plano. Puede seguir navegando.</small>
        </div>
    </div>

    {{-- Resultados --}}
    @if(isset($totalCount) && $totalCount > 0)
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title">
                <i class="fas fa-list mr-1"></i> Resultados OBNK
                <span class="badge badge-info ml-2">Total en SAP: {{ number_format($totalCount) }} registros</span>
                <small class="text-muted ml-2">(Mostrando los primeros 20)</small>
            </h3>
            <form action="{{ route('bankpages.cleanup') }}" method="POST" id="cleanup-form">
                @csrf
                <input type="hidden" name="account_code" value="{{ request('account_code') }}">
                <input type="hidden" name="account_name" value="{{ request('account_name') }}">
                <input type="hidden" name="date_from"    value="{{ request('date_from') }}">
                <input type="hidden" name="date_to"      value="{{ request('date_to') }}">
                <button type="submit" class="btn btn-danger btn-sm" id="btn-cleanup">
                    <i class="fas fa-trash-alt mr-1"></i>
                    Borrar los {{ number_format($totalCount) }} registros
                </button>
            </form>
        </div>
        <div class="card-body p-0">
            <table class="table table-bordered table-striped table-hover mb-0">
                <thead class="thead-dark">
                    <tr>
                        <th>Sequence</th><th>AcctCode</th><th>AcctName</th>
                        <th>DueDate</th><th>CreateDate</th>
                        <th class="text-right">Débito</th><th class="text-right">Crédito</th>
                        <th>Memo</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $r)
                    <tr>
                        <td>{{ $r['Sequence']    ?? '' }}</td>
                        <td>{{ $r['AccountCode'] ?? '' }}</td>
                        <td>{{ $r['AccountName'] ?? '' }}</td>
                        <td>{{ $r['DueDate']     ?? '' }}</td>
                        <td>{{ $r['CreateDate']  ?? $r['DocDate'] ?? '' }}</td>
                        <td class="text-right text-success">{{ number_format($r['DebitAmount']  ?? $r['DebAmount']  ?? 0, 2) }}</td>
                        <td class="text-right text-danger" >{{ number_format($r['CreditAmount'] ?? $r['CredAmnt']  ?? 0, 2) }}</td>
                        <td>{{ $r['Memo'] ?? '' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center text-muted">Sin registros</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @elseif(request()->hasAny(['account_code','date_from','account_name']) && (!isset($error) || !$error))
        <div class="alert alert-success"><i class="fas fa-check-circle mr-1"></i> No se encontraron registros con esos filtros. ¡La limpieza puede estar completa!</div>
    @endif

</div>
@stop

@section('css')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>.table td,.table th{vertical-align:middle;font-size:.875rem;}</style>
@stop

@section('js')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function(){
    // ── Confirmación antes de limpiar ──
    $('#cleanup-form').on('submit', function(e){
        e.preventDefault();
        var form = this;
        Swal.fire({
            icon: 'warning',
            title: '¿Confirmar borrado?',
            html: 'Se borrarán <strong>{{ number_format($totalCount ?? 0) }}</strong> registros de SAP.<br>Esta acción es <strong>irreversible</strong>.',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Sí, borrar todo',
            cancelButtonText: 'Cancelar'
        }).then(function(r){ if(r.isConfirmed) form.submit(); });
    });

    // ── Polling del progreso ──
    @php $cacheKey = session('cleanup_cache_key', session('cleanup_started') ? '' : null); @endphp
    @if(session('cleanup_started') && $cacheKey)
    var cacheKey = @json($cacheKey);
    var poll = setInterval(function(){
        $.getJSON('{{ route("bankpages.cleanup.status") }}?key=' + cacheKey, function(data){
            if(!data || data.status === 'not_found') { clearInterval(poll); return; }
            $('#prog-deleted').text((data.deleted||0).toLocaleString('es-ES'));
            $('#prog-failed').text(data.failed||0);

            if(data.status === 'done' || data.status === 'error'){
                clearInterval(poll);
                $('#cleanup-progress-card').removeClass('card-warning').addClass(data.status==='done'?'card-success':'card-danger');
                $('#prog-bar').removeClass('progress-bar-animated progress-bar-striped bg-warning')
                             .addClass(data.status==='done'?'bg-success':'bg-danger')
                             .text(data.status==='done'?'¡Completado!':'Error');
                Swal.fire({
                    icon: data.status==='done' ? 'success' : 'error',
                    title: data.status==='done' ? '¡Limpieza Completada!' : 'Error en la limpieza',
                    html: 'Borrados: <strong>'+(data.deleted||0).toLocaleString('es-ES')+'</strong>'
                         +(data.failed>0?'<br><span class="text-danger">Fallidos: '+data.failed+'</span>':'')
                         +(data.error?'<br><small>'+data.error+'</small>':''),
                    confirmButtonText: 'Ver resultado'
                }).then(function(){ window.location.reload(); });
            }
        });
    }, 3000);
    @endif
});
</script>
@stop
