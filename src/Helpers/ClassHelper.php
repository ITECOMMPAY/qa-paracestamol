<?php


namespace Paracetamol\Helpers;

class ClassHelper
{
    public static function getShortName(string $classNameFull) : string
    {
        return substr($classNameFull, strrpos($classNameFull, '\\') + 1);
    }

    public static function getNameFromFile(string $filepath) : string
    {
        $class = '';
        $namespace = '';
        $buffer = '';

        $fp = fopen($filepath, 'rb');

        $i = 0;

        while (!$class)
        {
            if (feof($fp))
            {
                break;
            }

            $buffer .= fread($fp, 512);
            $tokens = token_get_all($buffer);

            if (strpos($buffer, '{') === false)
            {
                continue;
            }

            $tokensCount = count($tokens);

            for (; $i < $tokensCount; $i++)
            {
                if ($tokens[$i][0] === T_NAMESPACE)
                {
                    for ($j = $i + 1; $j < $tokensCount; $j++)
                    {
                        if ($tokens[$j][0] === T_STRING)
                        {
                            $namespace .= '\\' . $tokens[$j][1];
                        }
                        else if ($tokens[$j] === '{' || $tokens[$j] === ';')
                        {
                            break;
                        }
                    }
                }

                if ($tokens[$i][0] === T_CLASS)
                {
                    for ($j = $i + 1; $j < $tokensCount; $j++)
                    {
                        if ($tokens[$j] === '{')
                        {
                            $class = $tokens[$i + 2][1];
                        }
                    }
                }
            }
        }

        return $namespace . '\\' . $class;
    }
}
