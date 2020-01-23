<?php
/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Utils\MessageHelpers;
use CharlotteDunois\Yasmin\Utils\URLHelpers;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use QueryPath\DOMQuery;
use React\Promise\PromiseInterface;
use Throwable;
use function html5qp;

class Lookup implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("lookup")
            ->setCallback([self::class, "lookupHandler"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function lookupHandler(EventData $data): ?PromiseInterface
    {
        try {
            $search = self::arg_substr($data->message->content, 1);

            return URLHelpers::resolveURLToData("https://2e.aonprd.com/Search.aspx?Query=" . urlencode($search))
                ->then(function ($response) use ($data, $search) {
                    $info = html5qp($response, '#ctl00_MainContent_SearchOutput')->children();

                    if (count($info) == 0) {
                        return $data->message->channel->send("No results found. 😦");
                    }

                    $results = [];
                    $category = null;
                    /** @var DOMQuery $child */
                    foreach ($info as $child) {
                        switch ($child->tag() ?? null) {
                            case 'b':
                            case 'h1':
                                $category = $child->text();
                                $results[$category] = [];
                                break;
                            case 'u':
                                $results[$category][] = [
                                    'name' => $child->text(),
                                    'url' => "https://2e.aonprd.com/" . html5qp($child, 'a')->attr("href"),
                                    'next' => rtrim($child->textAfter(), ", "),
                                ];
                                break;
                            default:
                                break;
                        }
                    }
                    $embed = self::easyEmbed($data->message);
                    $embed->setFooter("Archives of Nethys", "https://2e.aonprd.com/Images/Deities/Nethys.png");
                    $embed->setTitle("Looking up $search...");
                    foreach ($results as $cat => $items) {
                        $entries = implode(PHP_EOL, array_map(function ($v) {
                            return sprintf("[%s](%s)%s", $v['name'], $v['url'], $v['next']);
                        }, $items));
                        if (mb_strlen($entries) > 0) {
                            $paginate = MessageHelpers::splitMessage($entries,
                                ['maxLength' => 1024]);
                            $firstblock = true;
                            foreach ($paginate as $page) {
                                $inline = stripos($cat, "Exact Match") !== 0;
                                $embed->addField($firstblock ? $cat : $cat . " (cont.)", $page, $inline);
                                $firstblock = false;
                            }
                        }
                    }
                    return $data->message->channel->send("", ['embed' => $embed]);
                });
        } catch (\OutOfBoundsException $e) {
            return $data->message->channel->send("This command looks up SRD content from Archives of Nethys.\nUsage: `!lookup (term)`");
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }
}
