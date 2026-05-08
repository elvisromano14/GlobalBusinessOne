@extends('adminlte::master')

@section('adminlte_css')
    @yield('css')
@stop

@section('favicons')
    <link rel="icon" href="{{ asset('vendor/adminlte/dist/img/icon.jpg') }}?v=3" type="image/jpeg" />
@stop

@section('classes_body', 'login-page')

@section('body')
    <div class="login-box">
        <div class="login-logo">
            <img src="{{ asset('vendor/adminlte/dist/img/icon.jpg') }}" alt="Logo" height="50"><br>
            <a href="{{ url('/') }}"><b>Global</b>BusinessOne</a>
        </div>
        <div class="card {{ config('adminlte.classes_auth_card', 'card-outline card-primary') }}">
            <div class="card-header {{ config('adminlte.classes_auth_header', '') }}">
                <h3 class="card-title float-none text-center">
                    Inicie sesión para comenzar
                </h3>
            </div>
            <div class="card-body login-card-body {{ config('adminlte.classes_auth_body', '') }}">

                <form action="{{ route('login') }}" method="post">
                    @csrf
                    
                    {{-- Selección de Sociedad --}}
                    <div class="input-group mb-3">
                        <select name="company" class="form-control @error('company') is-invalid @enderror" required>
                            <option value="" disabled selected>Seleccione una Sociedad</option>
                            <option value="SBO_MANGO_BAJITO_PRODUCTIVA">MANGO BAJITO</option>
                            <option value="ZZZ_MB">PRUEBA</option>
                        </select>
                        <div class="input-group-append">
                            <div class="input-group-text">
                                <span class="fas fa-building"></span>
                            </div>
                        </div>
                        @error('company')
                            <span class="invalid-feedback" role="alert">
                                <strong>{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>

                    {{-- Campo de Usuario --}}
                    <div class="input-group mb-3">
                        <input type="text" name="username" class="form-control @error('username') is-invalid @enderror" 
                               value="{{ old('username') }}" placeholder="Usuario de SAP" required autofocus>
                        <div class="input-group-append">
                            <div class="input-group-text">
                                <span class="fas fa-user {{ config('adminlte.classes_auth_icon', '') }}"></span>
                            </div>
                        </div>
                        @error('username')
                            <span class="invalid-feedback" role="alert">
                                <strong>{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>

                    {{-- Campo de Contraseña --}}
                    <div class="input-group mb-3">
                        <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" 
                               placeholder="Contraseña" required>
                        <div class="input-group-append">
                            <div class="input-group-text">
                                <span class="fas fa-lock {{ config('adminlte.classes_auth_icon', '') }}"></span>
                            </div>
                        </div>
                        @error('password')
                            <span class="invalid-feedback" role="alert">
                                <strong>{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>

                    {{-- Botón de Ingresar --}}
                    <div class="row">
                        <div class="col-7"></div>
                        <div class="col-5">
                            <button type="submit" class="btn btn-block {{ config('adminlte.classes_auth_btn', 'btn-flat btn-primary') }}">
                                Ingresar
                            </button>
                        </div>
                    </div>
                </form>

            </div>
        </div>
    </div>
@stop

@section('adminlte_js')
    @yield('js')
@stop
