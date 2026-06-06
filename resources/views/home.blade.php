<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SwiftRide — Taxi & Tour Booking</title>
    <meta name="description" content="Book outstation cabs, airport transfers, sightseeing tours and holiday packages. 24/7 service, verified drivers, best price guarantee.">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { brand: '#FACC15' },
                    animation: {
                        'fade-up': 'fadeUp 0.6s ease forwards',
                        'fade-in': 'fadeIn 0.5s ease forwards',
                    },
                    keyframes: {
                        fadeUp:  { '0%': { opacity: 0, transform: 'translateY(24px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
                        fadeIn:  { '0%': { opacity: 0 }, '100%': { opacity: 1 } },
                    }
                }
            }
        }
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        .hero-bg {
            background: radial-gradient(ellipse at 65% 0%, rgba(250,204,21,.13) 0%, transparent 55%),
                        radial-gradient(ellipse at 10% 80%, rgba(250,204,21,.07) 0%, transparent 50%),
                        linear-gradient(160deg, #050505 0%, #0f1729 50%, #050505 100%);
        }
        .grid-pattern {
            background-image: linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
            background-size: 48px 48px;
        }
        .reveal { opacity: 0; transform: translateY(28px); transition: opacity .65s ease, transform .65s ease; }
        .reveal.visible { opacity: 1; transform: translateY(0); }
        .reveal-d1 { transition-delay: .08s; }
        .reveal-d2 { transition-delay: .18s; }
        .reveal-d3 { transition-delay: .28s; }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1) opacity(.35); }
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: #050505; }
        ::-webkit-scrollbar-thumb { background: #374151; border-radius: 3px; }
        .tab-scroll::-webkit-scrollbar { height: 0; }
    </style>
</head>
<body class="bg-gray-950 text-white font-sans antialiased" x-data="app()" x-init="init()">

{{-- ═══════════════════ NAVBAR ═══════════════════ --}}
<header :class="scrolled ? 'bg-gray-950/95 backdrop-blur border-b border-gray-800/50 shadow-xl shadow-black/40' : 'bg-transparent'"
    class="fixed top-0 inset-x-0 z-50 transition-all duration-300">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
        <a href="#" class="flex items-center gap-2 font-bold text-xl tracking-tight">
            <span class="text-2xl leading-none">🚖</span>
            <span>Swift<span class="text-yellow-400">Ride</span></span>
        </a>
        <nav class="hidden md:flex items-center gap-7 text-sm text-gray-400">
            <a href="#services"     class="hover:text-white transition-colors">Services</a>
            <a href="#how-it-works" class="hover:text-white transition-colors">How It Works</a>
            <a href="#fleet"        class="hover:text-white transition-colors">Fleet</a>
            <a href="#reviews"      class="hover:text-white transition-colors">Reviews</a>
        </nav>
        <a href="#book" class="hidden md:inline-flex items-center gap-1.5 bg-yellow-400 hover:bg-yellow-300 text-gray-900 font-semibold text-sm px-4 py-2.5 rounded-lg transition-all hover:shadow-lg hover:shadow-yellow-400/25">
            Book Now ↗
        </a>
        <button @click="nav=!nav" class="md:hidden p-2 text-gray-400 hover:text-white rounded-lg transition-colors">
            <svg x-show="!nav" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            <svg x-show="nav"  x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <div x-show="nav" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
        class="md:hidden bg-gray-950/98 backdrop-blur border-b border-gray-800 px-4 pb-4 space-y-0.5">
        <a href="#services"     @click="nav=false" class="block py-2.5 text-sm text-gray-400 hover:text-white transition-colors">Services</a>
        <a href="#how-it-works" @click="nav=false" class="block py-2.5 text-sm text-gray-400 hover:text-white transition-colors">How It Works</a>
        <a href="#fleet"        @click="nav=false" class="block py-2.5 text-sm text-gray-400 hover:text-white transition-colors">Fleet</a>
        <a href="#reviews"      @click="nav=false" class="block py-2.5 text-sm text-gray-400 hover:text-white transition-colors">Reviews</a>
        <a href="#book" @click="nav=false" class="block mt-3 text-center bg-yellow-400 hover:bg-yellow-300 text-gray-900 font-bold py-3 rounded-xl text-sm transition-colors">Book Now ↗</a>
    </div>
</header>

{{-- ═══════════════════ HERO ═══════════════════ --}}
<section class="hero-bg grid-pattern min-h-screen flex flex-col justify-center pt-16">
    <div class="max-w-6xl mx-auto w-full px-4 sm:px-6 py-16 lg:py-24">
        <div class="grid lg:grid-cols-2 gap-10 lg:gap-16 items-center">

            {{-- Left copy --}}
            <div class="space-y-7">
                <div class="inline-flex items-center gap-2 bg-yellow-400/10 border border-yellow-400/25 rounded-full px-4 py-1.5 text-yellow-400 text-xs sm:text-sm font-medium animate-fade-in">
                    <span class="w-1.5 h-1.5 bg-yellow-400 rounded-full animate-pulse"></span>
                    Trusted by 10,000+ travellers
                </div>
                <h1 class="text-5xl sm:text-6xl lg:text-[5.5rem] font-extrabold tracking-tight leading-[0.9] animate-fade-up">
                    Your Ride,<br><span class="text-yellow-400">Your Way</span>
                </h1>
                <p class="text-gray-400 text-lg sm:text-xl max-w-md leading-relaxed animate-fade-up" style="animation-delay:.12s">
                    Outstation &middot; Airport &middot; Sightseeing &middot; Tours. Verified drivers, fixed prices, zero hidden charges.
                </p>
                <div class="flex flex-wrap gap-3 animate-fade-up" style="animation-delay:.22s">
                    <a href="#book" class="inline-flex items-center gap-2 bg-yellow-400 hover:bg-yellow-300 text-gray-900 font-bold px-6 py-3.5 rounded-xl text-base transition-all hover:shadow-xl hover:shadow-yellow-400/25 hover:-translate-y-0.5 active:translate-y-0">
                        Book a Ride <span aria-hidden>→</span>
                    </a>
                    <a href="tel:+919876543210" class="inline-flex items-center gap-2 border border-gray-700 hover:border-gray-500 text-gray-300 hover:text-white px-6 py-3.5 rounded-xl text-base transition-all hover:bg-gray-900">
                        📞 Call Now
                    </a>
                </div>
                <div class="flex gap-8 pt-5 border-t border-gray-800/80 animate-fade-up" style="animation-delay:.3s">
                    @foreach([['10K+','Customers'],['500+','Cities'],['4.9★','Rating']] as [$n,$l])
                    <div>
                        <div class="text-2xl sm:text-3xl font-extrabold text-white">{{ $n }}</div>
                        <div class="text-xs text-gray-500 mt-0.5 uppercase tracking-wider">{{ $l }}</div>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Right — booking card --}}
            <div id="book" class="animate-fade-up" style="animation-delay:.16s"
                x-data="{
                    trip: 'oneway',
                    token: '',
                    success: '',
                    errors: [],
                    busy: false,
                    tabs:[
                        {key:'oneway',         label:'↗ One Way'},
                        {key:'roundtrip',      label:'⇆ Round Trip'},
                        {key:'airport_pickup', label:'✈ Pickup'},
                        {key:'airport_drop',   label:'✈ Drop'},
                        {key:'sightseen',      label:'🏛 Sightseeing'},
                        {key:'tour_package',   label:'🗺 Tour'}
                    ],
                    initForm(){ fetch('/api/csrf').then(r=>r.json()).then(d=>{this.token=d.token}) },
                    async submit(e){
                        e.preventDefault(); this.busy=true; this.errors=[];
                        const fd=new FormData(e.target);
                        fd.set('_token',this.token); fd.set('trip_type',this.trip);
                        const res=await fetch('/inquiry',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
                        this.busy=false;
                        if(res.ok||res.redirected){
                            fetch('/api/csrf').then(r=>r.json()).then(d=>{this.token=d.token});
                            this.success='Inquiry sent! We\'ll call you within 30 min.';
                            this.errors=[];
                            e.target.reset();
                        } else {
                            const j=await res.json().catch(()=>null);
                            this.errors=j?.errors?Object.values(j.errors).flat():['Something went wrong. Please try again.'];
                        }
                    }
                }" x-init="initForm()">

                <div class="bg-gray-900/90 backdrop-blur border border-gray-800 rounded-2xl overflow-hidden shadow-2xl shadow-black/60">

                    {{-- Tab bar --}}
                    <div class="tab-scroll flex overflow-x-auto bg-gray-950/70 border-b border-gray-800">
                        <template x-for="t in tabs" :key="t.key">
                            <button type="button" @click="trip=t.key"
                                :class="trip===t.key?'bg-yellow-400 text-gray-900 font-semibold':'text-gray-500 hover:text-white hover:bg-gray-800/70'"
                                class="flex-shrink-0 px-3 sm:px-4 py-3 text-xs sm:text-sm transition-colors whitespace-nowrap"
                                x-text="t.label">
                            </button>
                        </template>
                    </div>

                    <div class="p-5 sm:p-6">
                        <div x-show="success" x-cloak x-transition class="mb-4 bg-green-500/10 border border-green-500/30 rounded-xl p-3 text-green-400 text-sm text-center">
                            ✅ <span x-text="success"></span>
                        </div>
                        <div x-show="errors.length" x-cloak x-transition class="mb-4 bg-red-500/10 border border-red-500/30 rounded-xl p-3 text-red-400 text-sm space-y-0.5">
                            <template x-for="err in errors" :key="err"><div>&bull; <span x-text="err"></span></div></template>
                        </div>

                        <form @submit="submit($event)" class="space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <input type="text" name="name" required placeholder="Your Name *"
                                    class="col-span-2 sm:col-span-1 w-full bg-gray-800/70 border border-gray-700/60 rounded-lg px-3.5 py-2.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-yellow-400 transition">
                                <input type="tel" name="phone" required placeholder="Phone *"
                                    class="col-span-2 sm:col-span-1 w-full bg-gray-800/70 border border-gray-700/60 rounded-lg px-3.5 py-2.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-yellow-400 transition">
                                <input type="email" name="email" required placeholder="Email *"
                                    class="col-span-2 w-full bg-gray-800/70 border border-gray-700/60 rounded-lg px-3.5 py-2.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-yellow-400 transition">
                                <input type="text" name="pickup_location" required placeholder="Pickup Location *"
                                    class="col-span-2 sm:col-span-1 w-full bg-gray-800/70 border border-gray-700/60 rounded-lg px-3.5 py-2.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-yellow-400 transition">
                                <div class="col-span-2 sm:col-span-1" x-show="!['sightseen','tour_package'].includes(trip)" x-cloak>
                                    <input type="text" name="drop_location" placeholder="Drop Location"
                                        class="w-full bg-gray-800/70 border border-gray-700/60 rounded-lg px-3.5 py-2.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-yellow-400 transition">
                                </div>
                                <input type="date" name="pickup_date" required :min="new Date().toISOString().split('T')[0]"
                                    class="col-span-1 w-full bg-gray-800/70 border border-gray-700/60 rounded-lg px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-yellow-400 transition">
                                <div class="col-span-1" x-show="['roundtrip','sightseen','tour_package'].includes(trip)" x-cloak>
                                    <input type="date" name="return_date"
                                        class="w-full bg-gray-800/70 border border-gray-700/60 rounded-lg px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-yellow-400 transition">
                                </div>
                                <select name="passengers" required
                                    class="col-span-1 w-full bg-gray-800/70 border border-gray-700/60 rounded-lg px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-yellow-400 transition">
                                    @foreach(range(1,20) as $n)
                                    <option value="{{ $n }}">{{ $n }} Pax</option>
                                    @endforeach
                                </select>
                                <select name="vehicle_type" required
                                    class="col-span-1 w-full bg-gray-800/70 border border-gray-700/60 rounded-lg px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-yellow-400 transition">
                                    <option value="sedan">🚗 Sedan</option>
                                    <option value="suv">🚙 SUV</option>
                                    <option value="tempo_traveller">🚐 Tempo</option>
                                    <option value="bus">🚌 Bus</option>
                                </select>
                                <textarea name="notes" rows="2" placeholder="Special requirements (optional)"
                                    class="col-span-2 w-full bg-gray-800/70 border border-gray-700/60 rounded-lg px-3.5 py-2.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-yellow-400 transition resize-none"></textarea>
                            </div>
                            <button type="submit" :disabled="busy||!token"
                                :class="(busy||!token)?'opacity-60 cursor-wait':'hover:bg-yellow-300 hover:-translate-y-0.5 hover:shadow-lg hover:shadow-yellow-400/25'"
                                class="w-full bg-yellow-400 text-gray-900 font-bold py-3.5 rounded-xl text-sm transition-all">
                                <span x-show="!busy">Get Free Quote →</span>
                                <span x-show="busy" x-cloak>Submitting…</span>
                            </button>
                            <p class="text-center text-gray-600 text-xs">🔒 No spam &middot; Response within 30 min</p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Scroll arrow --}}
    <div class="flex justify-center pb-8 animate-bounce">
        <a href="#services" class="text-gray-700 hover:text-gray-400 transition-colors" aria-label="Scroll down">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7"/>
            </svg>
        </a>
    </div>
</section>

{{-- ═══════════════════ SERVICES ═══════════════════ --}}
<section id="services" class="py-20 px-4 sm:px-6">
    <div class="max-w-6xl mx-auto">
        <div class="text-center mb-14 reveal">
            <p class="text-yellow-400 text-xs font-bold uppercase tracking-[.2em] mb-2">What We Offer</p>
            <h2 class="text-3xl sm:text-4xl font-bold">Services for Every Journey</h2>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
            @foreach([
                ['↗','One Way Cab','Fixed price A→B travel. Outstation, intercity, no return charges.','oneway'],
                ['⇆','Round Trip','Return included. Driver waits. No surprise billing.','roundtrip'],
                ['✈','Airport Transfer','On-time pickup/drop. Flight tracking. Any airport.','airport_pickup'],
                ['🏛','Sightseeing','Local temples, forts, nature. Flexible, driver-guided.','sightseen'],
                ['🗺','Tour Packages','Multi-day curated trips. Customisable itinerary.','tour_package'],
                ['🏢','Corporate Cabs','Employee transport, events, client pickups.','oneway'],
            ] as [$icon,$title,$desc])
            <div class="reveal reveal-d{{ $loop->index % 3 + 1 }} group bg-gray-900/50 hover:bg-gray-900 border border-gray-800 hover:border-yellow-400/40 rounded-2xl p-6 transition-all duration-300 hover:-translate-y-1.5 cursor-pointer">
                <div class="w-12 h-12 bg-yellow-400/10 group-hover:bg-yellow-400/20 rounded-xl flex items-center justify-center text-2xl mb-4 transition-colors duration-300">{{ $icon }}</div>
                <h3 class="font-semibold text-white mb-2 text-lg">{{ $title }}</h3>
                <p class="text-gray-500 text-sm leading-relaxed">{{ $desc }}</p>
                <div class="mt-5 flex items-center gap-1 text-yellow-400 text-sm font-medium group-hover:gap-2 transition-all duration-200">Book now <span>→</span></div>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════ HOW IT WORKS ═══════════════════ --}}
<section id="how-it-works" class="py-20 px-4 sm:px-6 bg-gray-900/25 relative overflow-hidden">
    <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_50%_0%,rgba(250,204,21,.04)_0%,transparent_65%)]"></div>
    <div class="max-w-5xl mx-auto relative">
        <div class="text-center mb-14 reveal">
            <p class="text-yellow-400 text-xs font-bold uppercase tracking-[.2em] mb-2">Simple Process</p>
            <h2 class="text-3xl sm:text-4xl font-bold">Book in 3 Easy Steps</h2>
        </div>
        <div class="relative grid md:grid-cols-3 gap-8 md:gap-6">
            <div class="hidden md:block absolute top-10 left-[calc(16.66%+20px)] right-[calc(16.66%+20px)] h-px bg-gradient-to-r from-yellow-400/30 via-yellow-400/60 to-yellow-400/30"></div>
            @foreach([
                ['📋','01','Fill the Form','Enter trip details, locations, date, passengers and vehicle preference.'],
                ['📞','02','Get a Callback','Team calls within 30 minutes to confirm fare and driver details.'],
                ['🚗','03','Enjoy the Ride','Driver arrives on time. Comfortable, safe, professional.'],
            ] as [$icon,$step,$title,$desc])
            <div class="reveal reveal-d{{ $loop->index + 1 }} text-center relative z-10">
                <div class="w-20 h-20 bg-gray-900 border-2 border-yellow-400/50 rounded-full flex items-center justify-center text-3xl mx-auto mb-5 shadow-lg shadow-yellow-400/10">{{ $icon }}</div>
                <div class="text-[10px] text-yellow-400 font-bold tracking-[.2em] uppercase mb-1">Step {{ $step }}</div>
                <h3 class="font-semibold text-lg mb-2">{{ $title }}</h3>
                <p class="text-gray-500 text-sm leading-relaxed max-w-xs mx-auto">{{ $desc }}</p>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════ FLEET ═══════════════════ --}}
<section id="fleet" class="py-20 px-4 sm:px-6">
    <div class="max-w-6xl mx-auto">
        <div class="text-center mb-14 reveal">
            <p class="text-yellow-400 text-xs font-bold uppercase tracking-[.2em] mb-2">Our Fleet</p>
            <h2 class="text-3xl sm:text-4xl font-bold">Pick Your Comfort</h2>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
            @foreach([
                ['🚗','Sedan','1–4 passengers','Dzire · Etios · City','Economy & business'],
                ['🚙','SUV / Innova','1–7 passengers','Innova · Ertiga · Scorpio','Families & groups'],
                ['🚐','Tempo Traveller','8–14 passengers','Force Traveller','Group trips & pilgrimages'],
                ['🚌','Bus / Coach','15–50 passengers','Mini Bus · Coach','Corporate & large tours'],
            ] as [$icon,$name,$pax,$models,$use])
            <div class="reveal reveal-d{{ $loop->index % 4 + 1 }} group bg-gray-900/60 hover:bg-gray-900 border border-gray-800 hover:border-yellow-400/30 rounded-2xl p-5 sm:p-6 transition-all duration-300 hover:-translate-y-1.5">
                <div class="text-4xl sm:text-5xl mb-4 group-hover:scale-110 transition-transform duration-300 leading-none">{{ $icon }}</div>
                <h3 class="font-bold text-base sm:text-lg mb-1">{{ $name }}</h3>
                <div class="text-yellow-400 text-xs font-semibold mb-2">👥 {{ $pax }}</div>
                <p class="text-gray-500 text-xs mb-1">{{ $models }}</p>
                <p class="text-gray-600 text-xs mb-4">{{ $use }}</p>
                <a href="#book" class="block text-center text-xs font-bold text-gray-900 bg-yellow-400 hover:bg-yellow-300 py-2 rounded-lg transition-colors">
                    Book →
                </a>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════ STATS BAND ═══════════════════ --}}
<section class="py-12 px-4 sm:px-6 border-y border-gray-800/60 bg-gray-900/20">
    <div class="max-w-5xl mx-auto grid grid-cols-2 md:grid-cols-4 gap-6 text-center">
        @foreach([
            ['10,000+','Happy Customers','😊'],
            ['500+','Cities Covered','🗺'],
            ['24/7','Always On Call','⏰'],
            ['4.9 / 5','Average Rating','⭐'],
        ] as [$num,$label,$icon])
        <div class="reveal reveal-d{{ $loop->index + 1 }}">
            <div class="text-2xl mb-1.5">{{ $icon }}</div>
            <div class="text-2xl sm:text-3xl font-extrabold text-white">{{ $num }}</div>
            <div class="text-xs text-gray-500 mt-0.5 uppercase tracking-wider">{{ $label }}</div>
        </div>
        @endforeach
    </div>
</section>

{{-- ═══════════════════ REVIEWS ═══════════════════ --}}
<section id="reviews" class="py-20 px-4 sm:px-6">
    <div class="max-w-6xl mx-auto">
        <div class="text-center mb-14 reveal">
            <p class="text-yellow-400 text-xs font-bold uppercase tracking-[.2em] mb-2">Testimonials</p>
            <h2 class="text-3xl sm:text-4xl font-bold">What Our Riders Say</h2>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            @foreach([
                ['Rajesh M.','Mumbai → Pune','⭐⭐⭐⭐⭐','Booked a midnight one-way cab. Driver was on time, car was spotless, price exactly as quoted. No hidden fees. Will definitely book again!'],
                ['Priya S.','Airport Transfer','⭐⭐⭐⭐⭐','Flight landed 40 min early. They tracked it and the driver was already waiting. Smooth, professional, great communication throughout.'],
                ['Amit K.','5-Day Rajasthan Tour','⭐⭐⭐⭐⭐','Amazing experience. Driver knew every landmark, very helpful with luggage and timing. Family loved it — will recommend to everyone.'],
            ] as [$name,$route,$stars,$review])
            <div class="reveal reveal-d{{ $loop->index + 1 }} bg-gray-900/60 border border-gray-800 rounded-2xl p-6 space-y-4">
                <div class="text-base leading-none">{{ $stars }}</div>
                <p class="text-gray-300 text-sm leading-relaxed">"{{ $review }}"</p>
                <div class="pt-3 border-t border-gray-800/80 flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-yellow-400/20 flex items-center justify-center text-yellow-400 font-bold text-sm">
                        {{ substr($name, 0, 1) }}
                    </div>
                    <div>
                        <div class="font-semibold text-sm">{{ $name }}</div>
                        <div class="text-gray-500 text-xs">{{ $route }}</div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════ BOTTOM CTA ═══════════════════ --}}
<section class="py-20 px-4 sm:px-6 relative overflow-hidden">
    <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_50%_50%,rgba(250,204,21,.08)_0%,transparent_65%)]"></div>
    <div class="max-w-3xl mx-auto text-center relative space-y-6 reveal">
        <h2 class="text-3xl sm:text-4xl font-bold">Ready to Hit the Road?</h2>
        <p class="text-gray-400 text-lg">Free quote in 30 seconds. No booking fee. Pay only when you ride.</p>
        <div class="flex flex-col sm:flex-row gap-3 justify-center">
            <a href="#book" class="inline-flex items-center justify-center gap-2 bg-yellow-400 hover:bg-yellow-300 text-gray-900 font-bold px-8 py-4 rounded-xl text-base transition-all hover:shadow-xl hover:shadow-yellow-400/25 hover:-translate-y-0.5">
                Book a Ride →
            </a>
            <a href="tel:+919876543210" class="inline-flex items-center justify-center gap-2 border border-gray-700 hover:border-gray-500 text-gray-300 hover:text-white px-8 py-4 rounded-xl text-base transition-all hover:bg-gray-900">
                📞 +91 98765 43210
            </a>
        </div>
    </div>
</section>

{{-- ═══════════════════ FOOTER ═══════════════════ --}}
<footer class="bg-gray-950 border-t border-gray-800/60 px-4 sm:px-6 pt-12 pb-6">
    <div class="max-w-6xl mx-auto">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-8 mb-10">
            <div>
                <div class="font-bold text-xl mb-3">🚖 Swift<span class="text-yellow-400">Ride</span></div>
                <p class="text-gray-500 text-sm leading-relaxed max-w-xs">Safe, reliable cab service across India. 24/7 available, verified drivers, fixed prices.</p>
            </div>
            <div>
                <div class="font-semibold text-sm mb-4 text-gray-300 uppercase tracking-widest">Services</div>
                <ul class="space-y-2 text-sm text-gray-500">
                    <li><a href="#book" class="hover:text-gray-300 transition-colors">One Way Cab</a></li>
                    <li><a href="#book" class="hover:text-gray-300 transition-colors">Round Trip</a></li>
                    <li><a href="#book" class="hover:text-gray-300 transition-colors">Airport Transfer</a></li>
                    <li><a href="#book" class="hover:text-gray-300 transition-colors">Tour Packages</a></li>
                </ul>
            </div>
            <div>
                <div class="font-semibold text-sm mb-4 text-gray-300 uppercase tracking-widest">Contact</div>
                <ul class="space-y-2 text-sm text-gray-500">
                    <li>📞 +91 98765 43210</li>
                    <li>✉ hello@swiftride.in</li>
                    <li>⏰ Available 24 &times; 7</li>
                </ul>
            </div>
        </div>
        <div class="border-t border-gray-800/60 pt-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-600">
            <span>&copy; {{ date('Y') }} SwiftRide. All rights reserved.</span>
            <span>Made for Indian travellers 🇮🇳</span>
        </div>
    </div>
</footer>

{{-- Mobile sticky book bar --}}
<div x-show="!atBook" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
    class="md:hidden fixed bottom-0 inset-x-0 z-40 p-3 bg-gray-950/95 backdrop-blur border-t border-gray-800">
    <a href="#book" class="block text-center bg-yellow-400 hover:bg-yellow-300 text-gray-900 font-bold py-3.5 rounded-xl text-sm transition-colors shadow-lg shadow-yellow-400/20">
        Book a Cab Now →
    </a>
</div>

<script>
function app() {
    return {
        scrolled: false,
        nav: false,
        atBook: false,
        init() {
            window.addEventListener('scroll', () => {
                this.scrolled = window.scrollY > 48;
                const el = document.getElementById('book');
                if (el) {
                    const r = el.getBoundingClientRect();
                    this.atBook = r.top < window.innerHeight * 1.2 && r.bottom > -100;
                }
            }, { passive: true });

            const io = new IntersectionObserver(entries => {
                entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('visible'); });
            }, { threshold: 0.1, rootMargin: '0px 0px -32px 0px' });
            document.querySelectorAll('.reveal').forEach(el => io.observe(el));
        }
    }
}
</script>

</body>
</html>
