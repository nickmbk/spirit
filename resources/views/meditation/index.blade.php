@extends('layouts.app')

@section('content')
    <!--<a href="{{ URL::previous() }}" class="back-link"><-- Back</a>-->
    <form action="/meditation" method="post" class="meditation__form">
        @csrf
        <div class="meditation__field">
            <label for="first-name" class="meditation__label{{$errors->first('first_name') ? ' meditation__label--error' : ''}}">
                First Name
            </label>
            <input type="text" id="first-name" name="first_name" class="meditation__input{{$errors->first('first_name') ? ' meditation__input--error' : ''}}" value="{{ old('first_name') }}">
            <div>@error('first_name') {{ $message }} @enderror</div>
        </div>
        <div class="meditation__field">
            <label for="email" class="meditation__label{{$errors->first('email') ? ' meditation__label--error' : ''}}">
                Email
            </label>
            <input type="email" id="email" name="email" class="meditation__input{{$errors->first('email') ? ' meditation__input--error' : ''}}" value="{{ old('email') }}">
            <div>@error('email') {{ $message }} @enderror</div>
        </div>
        <div class="meditation__field">
            <label for="birth-date" class="meditation__label{{$errors->first('birth_date') ? ' meditation__label--error' : ''}}">
                Birth Date
            </label>
            <input type="date" id="birth-date" name="birth_date" class="meditation__input{{$errors->first('birth_date') ? ' meditation__input--error' : ''}}" value="{{ old('birth_date') }}">
            <div>@error('birth_date') {{ $message }} @enderror</div>
        </div>
        <div class="meditation__field">
            <label for="style" class="meditation__label{{$errors->first('style') ? ' meditation__label--error' : ''}}">
                Meditation Style
            </label>
            <select id="style" name="style" class="meditation__input{{$errors->first('style') ? ' meditation__input--error' : ''}}">
                <option value="" disabled {{ old('style') == '' ? '' : 'selected' }}>Select a style</option>
                <option value="relaxing" {{ old('style') == 'relaxing' ? 'selected' : '' }}>Relaxing</option>
                <option value="energising" {{ old('style') == 'energising' ? 'selected' : '' }}>Energising</option>
            </select>
            <div>@error('style') {{ $message }} @enderror</div>
        </div>
        <div class="meditation__field">
            <label for="goals" class="meditation__label{{$errors->first('goals') ? ' meditation__label--error' : ''}}">
                Goals
            </label>
            <textarea id="goals" name="goals" class="meditation__input{{$errors->first('goals') ? ' meditation__input--error' : ''}}">{{ old('goals') }}</textarea>
            <div>@error('goals') {{ $message }} @enderror</div>
        </div>
        <div class="meditation__field">
            <label for="challenges" class="meditation__label{{$errors->first('challenges') ? ' meditation__label--error' : ''}}">
                Challenges
            </label>
            <textarea id="challenges" name="challenges" class="meditation__input{{$errors->first('challenges') ? ' meditation__input--error' : ''}}">{{ old('challenges') }}</textarea>
            <div>@error('challenges') {{ $message }} @enderror</div>
        </div>
        <div class="meditation__field meditation__field--checkbox">
            <input id="consent" type="checkbox" name="consent" class="meditation__input{{$errors->first('consent') ? ' meditation__input--error' : ''}}" {{ old('consent') ? 'checked' : '' }}>
            <label for="consent" class="meditation__label{{$errors->first('consent') ? ' meditation__label--error' : ''}}">
                I consent to the processing of my personal data
            </label>
            <div>@error('consent') {{ $message }} @enderror</div>
        </div>
        <div class="meditation__field">
            <button type="submit" class="meditation__button">
                Create Meditation
            </button>
        </div>
    </form>
@endsection