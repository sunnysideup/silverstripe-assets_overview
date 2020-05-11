<?php

namespace Sunnysideup\AssetsOverview\Api;

class FilesSimilarToMe
{
    protected function workOutSimilarity()
    {
        set_time_limit(240);
        $engine = new CompareImages();
        $this->setFilesAsArrayList();
        $a = clone $this->filesAsArrayList;
        $b = clone $this->filesAsArrayList;
        $c = clone $this->filesAsArrayList;
        $alreadyDone = [];
        foreach ($a as $file) {
            $nameOne = $file->Path;
            $nameOneFromAssets = $file->PathFromAssetsFolder;
            if (! in_array($nameOne, $alreadyDone, true)) {
                $easyFind = false;
                $sortArray = [];
                foreach ($b as $compareImage) {
                    $nameTwo = $compareImage->Path;
                    if ($nameOne !== $nameTwo) {
                        $fileNameTest = $file->FileName && $file->FileName === $compareImage->FileName;
                        $fileSizeTest = $file->FileSize > 0 && $file->FileSize === $compareImage->FileSize;
                        if ($fileNameTest || $fileSizeTest) {
                            $easyFind = true;
                            $alreadyDone[$nameOne] = $nameOneFromAssets;
                            $alreadyDone[$compareImage->Path] = $nameOneFromAssets;
                        } elseif ($easyFind === false && $file->ImageIsImage) {
                            if ($file->ImageRatio === $compareImage->ImageRatio && $file->ImageRatio > 0) {
                                $score = $engine->compare($nameOne, $nameTwo);
                                $sortArray[$nameTwo] = $score;
                                break;
                            }
                        }
                    }
                }
                if ($easyFind === false) {
                    if (count($sortArray)) {
                        asort($sortArray);
                        reset($sortArray);
                        $mostSimilarKey = key($sortArray);
                        foreach ($c as $findImage) {
                            if ($findImage->Path === $mostSimilarKey) {
                                $alreadyDone[$nameOne] = $nameOneFromAssets;
                                $alreadyDone[$findImage->Path] = $nameOneFromAssets;
                                break;
                            }
                        }
                    } else {
                        $alreadyDone[$file->Path] = '[N/A]';
                    }
                }
            }
        }
        foreach ($this->filesAsArrayList as $file) {
            $file->MostSimilarTo = $alreadyDone[$file->Path] ?? '[N/A]';
        }
        $this->setFilesAsSortedArrayList('MostSimilarTo', 'MostSimilarTo');
    }
}
