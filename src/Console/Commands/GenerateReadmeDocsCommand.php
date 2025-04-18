<?php

namespace Nuimarkets\LaravelSharedUtils\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateReadmeDocsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nuimarkets:generate-readme-docs 
                            {--path= : Path to Laravel project (defaults to current directory)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate documentation for routes, scheduled tasks, and queue jobs for README.md';

    /**
     * Project base path
     *
     * @var string
     */
    protected $basePath;

    /**
     * README path
     *
     * @var string
     */
    protected $readmePath;

    /**
     * Extracted routes
     *
     * @var array
     */
    protected $routes = [];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->basePath = $this->option('path') ?: base_path();
        $this->readmePath = $this->basePath . '/README.md';

        $this->info("Generating documentation for project at: " . $this->basePath);

        if (!File::exists($this->readmePath)) {
            $this->error("README.md not found at {$this->readmePath}");
            return 1;
        }

        // Extract information
        $this->extractApiRoutes();

        // You can add more extraction methods here
        // $this->extractScheduledTasks();
        // $this->extractQueueJobs();

        // Generate documentation
        $docs = $this->generateDocumentation();

        // Update README
        $this->updateReadme($docs);

        $this->info("Documentation generated successfully!");
        return 0;
    }

    /**
     * Extract API routes directly from Laravel's router
     */
    protected function extractApiRoutes()
    {
        try {
            // Get all the registered routes
            $router = app('router');
            $routes = $router->getRoutes();

            foreach ($routes as $route) {
                // Process route information
                $methods = $route->methods();
                $uri = $route->uri();

                // Skip routes that are not API routes (if they don't start with api/)
                if (strpos($uri, 'api/') !== 0) {
                    continue;
                }

                // Skip HEAD and OPTIONS methods as they're typically not documented
                $methods = array_diff($methods, ['HEAD', 'OPTIONS']);

                foreach ($methods as $method) {
                    // Skip if route starts with underscore (convention for internal routes)
                    if (strpos($uri, '_') === 0) {
                        continue;
                    }

                    // Get controller action if available
                    $action = '';
                    if (isset($route->getAction()['controller'])) {
                        $controllerAction = $route->getAction()['controller'];
                        // Extract the class name and method from the controller string
                        if (strpos($controllerAction, '@') !== false) {
                            list($controller, $method) = explode('@', $controllerAction);
                            $controllerName = class_basename($controller);
                            $action = $controllerName . '@' . $method;
                        } else {
                            // For invokable controllers or closure-based routes
                            $action = is_string($controllerAction) ? class_basename($controllerAction) : 'Closure';
                        }
                    }

                    $this->routes[] = [
                        'method' => strtoupper($method),
                        'path' => '/' . $uri,
                        'action' => $action,
                    ];
                }
            }

            // Sort routes by path for better readability
            usort($this->routes, function($a, $b) {
                return strcmp($a['path'], $b['path']);
            });

        } catch (\Exception $e) {
            $this->warn("Could not extract routes from router: " . $e->getMessage());

            // Fallback: Try to parse routes directly from the route file
            $this->extractRoutesFromFile();
        }
    }

    /**
     * Fallback method to extract routes from the routes file if router extraction fails
     */
    protected function extractRoutesFromFile()
    {
        $routesPath = $this->basePath . '/routes/api.php';

        if (!File::exists($routesPath)) {
            $this->warn("WARNING: routes/api.php not found.");
            return;
        }

        $this->info("Using fallback method to parse routes file directly.");

        // Read in the file content
        $routeContent = File::get($routesPath);

        // Define patterns to match common Laravel route definitions
        $patterns = [
            // Match Route::get('/path', [Controller::class, 'method'])
            '/Route::(get|post|put|delete|patch|options)\s*\(\s*[\'"]([^\'"]+)[\'"],\s*\[\s*([^:]+)::class\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]\s*\)/',

            // Match $router->get('/path', [Controller::class, 'method'])
            '/\$router->(get|post|put|delete|patch|options)\s*\(\s*[\'"]([^\'"]+)[\'"],\s*\[\s*([^:]+)::class\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]\s*\)/',
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $routeContent, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $method = strtoupper($match[1]);
                $path = $match[2];
                $controller = isset($match[3]) ? basename(trim($match[3])) : '';
                $action = isset($match[4]) ? trim($match[4]) : '';

                // Prepare the controller action string
                $controllerAction = $controller ? ($controller . '@' . $action) : '';

                // Skip if route starts with underscore
                if (strpos($path, '_') === 0) continue;

                $this->routes[] = [
                    'method' => $method,
                    'path' => $path,
                    'action' => $controllerAction,
                ];
            }
        }

        // Sort routes by path for better readability
        usort($this->routes, function($a, $b) {
            return strcmp($a['path'], $b['path']);
        });
    }

    /**
     * Generate markdown documentation
     */
    protected function generateDocumentation()
    {
        $docs = "## API Endpoints\n\n";

        if (empty($this->routes)) {
            $docs .= "No API endpoints found.\n\n";
        } else {
            $docs .= "| Method | Endpoint | Controller Action |\n";
            $docs .= "|--------|----------|------------------|\n";

            foreach ($this->routes as $route) {
                $actionInfo = isset($route['action']) ? $route['action'] : '';
                $docs .= "| " . $route['method'] . " | " . $route['path'] . " | " . $actionInfo . " |\n";
            }

            $docs .= "\n";
        }

        
        return $docs;
    }

    /**
     * Update README file with generated documentation
     */
    protected function updateReadme($docs)
    {
        $readme = File::get($this->readmePath);

        // Define the marker for auto-generated documentation
        $startMarker = "<!-- AUTO-GENERATED DOCUMENTATION START -->";
        $endMarker = "<!-- AUTO-GENERATED DOCUMENTATION END -->";

        // Check if markers already exist
        if (strpos($readme, $startMarker) !== false && strpos($readme, $endMarker) !== false) {
            // Replace existing documentation
            $pattern = '/' . preg_quote($startMarker, '/') . '.*?' . preg_quote($endMarker, '/') . '/s';
            $replacement = $startMarker . "\n" . $docs . $endMarker;
            $newReadme = preg_replace($pattern, $replacement, $readme);
        } else {
            // Append to the end of README
            $newReadme = $readme . "\n\n" . $startMarker . "\n" . $docs . $endMarker . "\n";
        }

        File::put($this->readmePath, $newReadme);
        $this->info("README.md updated with documentation.");
    }
}