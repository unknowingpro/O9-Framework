<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Seo;

/** Public server-rendered pages (home, about) — a View::render() demo. */
final class PageController extends BaseController
{
    public function home(Request $request): never
    {
        Seo::set(
            title: (string) config('app.name', 'O9') . ' — Home',
            description: 'Built on the O9 framework.',
        );
        $this->render('pages/home', ['appName' => (string) config('app.name', 'O9')]);
    }

    public function about(Request $request): never
    {
        Seo::set(title: 'About');
        $this->render('pages/about');
    }
}
