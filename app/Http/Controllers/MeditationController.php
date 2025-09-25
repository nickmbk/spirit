<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateScriptJob;
use Illuminate\Http\Request;
use App\Models\Meditation;

class MeditationController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'first_name' => 'required | string | max:255',
            'email' => 'required | email | max:255',
            'birth_date' => 'required | date',
            'style' => 'required | string | max:255',
            'goals' => 'required | string | max:1000',
            'challenges' => 'required | string | max:1000',
            'consent' => 'accepted',
        ],[
            'first_name.required' => 'Please enter your first name.',
            'email.required' => 'Please enter your email address.',
            'birth_date.required' => 'Please enter your birth date.',
            'style.required' => 'Please enter your preferred meditation style.',
            'goals.required' => 'Please enter your goals.',
            'challenges.required' => 'Please enter your challenges.',
            'consent.accepted' => 'You must accept the terms and conditions.',
        ]
        );

        $meditation = new Meditation;
        $meditation->first_name = $request->input('first_name');
        $meditation->email = $request->input('email');
        $meditation->birth_date = $request->input('birth_date');
        $meditation->style = $request->input('style');
        $meditation->goals = $request->input('goals');
        $meditation->challenges = $request->input('challenges');
    
        $result = $meditation->save();

        if ($result) {
            GenerateScriptJob::dispatch($meditation->id, $meditation->created_at->format('dmY'));
            return redirect()->route('meditation.thanks', $meditation);
        } else {
            return redirect()->back()->withInput();
        }
    }

    public function thanks()
    {
        return view('meditation.thanks');
    }
}
