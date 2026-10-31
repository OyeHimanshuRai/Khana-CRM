<?php

/*
|--------------------------------------------------------------------------
| Slider layouts
|--------------------------------------------------------------------------
|
| The placements a slide can be assigned to. Keys are stored in
| sliders.layout; labels are what the form and the listing show.
|
| Kept here rather than as a hard-coded list so adding a placement is one
| line, and so the controller can validate against exactly what the form
| offered - a typo can never store a layout nothing renders.
|
*/

return [

    // The landing page hero's rotating background - see LandingController::backdrops().
    'main_banner' => 'Landing Hero Background',
    'enjoy_the_moment' => 'Enjoy the Moment',
    'gifting_guide' => 'Gifting Guide',
    'swarnaudayam' => 'Swarnaudayam',
    'crafting' => 'Crafting',
    'instagram_posts' => 'Instagram Posts',
    'layout_7' => 'Layout 7',
    'top_strip_bar' => 'Landing Top Strip',

];
