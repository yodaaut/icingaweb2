<?php
/* Icinga Web 2 | (c) 2013-2015 Icinga Development Team | GPLv2+ */

namespace Icinga\File\Ini;

use Icinga\File\Ini\Dom\Section;
use Icinga\File\Ini\Dom\Comment;
use Icinga\File\Ini\Dom\Document;
use Icinga\File\Ini\Dom\Directive;
use Icinga\Application\Logger;
use Icinga\Exception\ConfigurationError;

class IniParser
{
    const LINE_START = 0;
    const SECTION = 1;
    const DIRECTIVE_KEY = 4;
    const DIRECTIVE_VALUE_START = 5;
    const DIRECTIVE_VALUE = 6;
    const DIRECTIVE_VALUE_QUOTED = 7;
    const COMMENT = 8;
    const COMMENT_END = 9;
    const LINE_END = 10;

    private static function throwParseError($message, $line)
    {
        throw new ConfigurationError(sprintf('Ini parser error: %s. (l. %d)', $message, $line));
    }

    /**
     * Read the ini file contained in a string and return a mutable DOM that can be used
     * to change the content of an INI file.
     *
     * @param $str                  A string containing the whole ini file
     *
     * @return Document             The mutable DOM object.
     * @throws ConfigurationError   In case the file is not parseable
     */
    public static function parseIni($str)
    {
        $doc = new Document();
        $sec = null;
        $dir = null;
        $coms = array();
        $state = self::LINE_START;
        $token = '';
        $line = 0;

        for ($i = 0; $i < strlen($str); $i++) {
            $s = $str[$i];
            switch ($state) {
                case self::LINE_START:
                    if (ctype_space($s)) {
                        continue;
                    }
                    switch ($s) {
                        case '[':
                            $state = self::SECTION;
                            break;
                        case ';':
                            $state = self::COMMENT;
                            break;
                        default:
                            $state = self::DIRECTIVE_KEY;
                            $token = $s;
                            break;
                    }
                    break;

                case self::SECTION:
                    if ($s === "\n") {
                        self::throwParseError('Unterminated SECTION', $line);
                    } if ($s !== ']') {
                        $token .= $s;
                    } else {
                        $sec = new Section($token);
                        $sec->commentsPre = $coms;
                        $doc->addSection($sec);
                        $dir = null;
                        $coms = array();

                        $state = self::LINE_END;
                        $token = '';
                    }
                    break;

                case self::DIRECTIVE_KEY:
                    if ($s !== '=') {
                        $token .= $s;
                    } else {
                        $dir = new Directive($token);
                        $dir->commentsPre = $coms;

                        if (isset($sec)) {
                            $sec->addDirective($dir);
                        } else {
                            Logger::warning(sprintf(
                                'Ini parser warning: section-less directive "%s" ignored. (l. %d)',
                                $token,
                                $line
                            ));
                        }

                        $coms = array();
                        $state = self::DIRECTIVE_VALUE_START;
                        $token = '';
                    }
                    break;

                case self::DIRECTIVE_VALUE_START:
                    if (ctype_space($s)) {
                        continue;
                    } elseif ($s === '"') {
                        $state = self::DIRECTIVE_VALUE_QUOTED;
                    } else {
                        $state = self::DIRECTIVE_VALUE;
                        $token = $s;
                    }
                    break;

                case self::DIRECTIVE_VALUE:
                    if ($s === "\n" || $s === ";") {
                        $dir->setValue($token);
                        $token = '';

                        if ($s === "\n") {
                            $state = self::LINE_START;
                            $line ++;
                        } elseif ($s === ';') {
                            $state = self::COMMENT;
                        }
                    } else {
                        $token .= $s;
                    }
                    break;

                case self::DIRECTIVE_VALUE_QUOTED:
                    if ($s === "\n") {
                        self::throwParseError('Unterminated DIRECTIVE_VALUE_QUOTED', $line);
                    } elseif ($s !== '"') {
                        $token .= $s;
                    } else {
                        $dir->setValue($token);
                        $token = '';
                        $state = self::LINE_END;
                    }
                    break;

                case self::COMMENT:
                case self::COMMENT_END:
                    if ($s !== "\n") {
                        $token .= $s;
                    } else {
                        $com = new Comment();
                        $com->content = $token;
                        $token = '';

                        // Comments at the line end belong to the current line's directive or section. Comments
                        // on empty lines belong to the next directive that shows up.
                        if ($state === self::COMMENT_END) {
                            if (isset($dir)) {
                                $dir->commentPost = $com;
                            } else {
                                $sec->commentPost = $com;
                            }
                        } else {
                            $coms[] = $com;
                        }
                        $state = self::LINE_START;
                        $line ++;
                    }
                    break;

                case self::LINE_END:
                    if ($s === "\n") {
                        $state = self::LINE_START;
                        $line ++;
                    } elseif ($s === ';') {
                        $state = self::COMMENT_END;
                    }
                    break;
            }
        }

        // process the last token
        switch ($state) {
            case self::COMMENT:
            case self::COMMENT_END:
                $com = new Comment();
                $com->content = $token;
                if ($state === self::COMMENT_END) {
                    if (isset($dir)) {
                        $dir->commentPost = $com;
                    } else {
                        $sec->commentPost = $com;
                    }
                } else {
                    $coms[] = $com;
                }
                break;

            case self::DIRECTIVE_VALUE:
                $dir->setValue($token);
                $sec->addDirective($dir);
                break;

            case self::DIRECTIVE_VALUE_QUOTED:
            case self::DIRECTIVE_KEY:
            case self::SECTION:
                self::throwParseError('File ended in unterminated state ' . $state, $line);
        }
        if (! empty($coms)) {
            $doc->commentsDangling = $coms;
        }
        return $doc;
    }
}
