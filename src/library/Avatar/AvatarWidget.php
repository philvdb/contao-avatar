<?php

/**
 * Avatar for Contao Open Source CMS
 *
 * Copyright (C) 2013 Kirsten Roschanski
 * Copyright (C) 2013 Tristan Lins <http://bit3.de>
 *
 * @package    Avatar
 * @license    http://opensource.org/licenses/lgpl-3.0.html LGPL
 */

namespace KirstenRoschanski\Avatar\Widget;

/**
 * Class AvatarWidget
 *
 * Widget for members avatar.
 *
 * @copyright  Kirsten Roschanski (C) 2013
 * @copyright  Tristan Lins (C) 2013
 * @author     Kirsten Roschanski <kirsten@kat-webdesign.de>
 * @author     Tristan Lins <tristan.lins@bit3.de>
 */
class AvatarWidget extends \Widget implements \uploadable
{

    /**
     * Template
     *
     * @var string
     */
    protected $strTemplate = 'form_avatar';

    /**
     * Avatar preview size
     *
     * @var array
     */
    protected $arrAvatarPreviewSize = array();

    /**
     * Submit user input
     *
     * @var boolean
     */
    protected $blnSubmitInput = true;

    /**
     * Add specific attributes
     *
     * @param string
     * @param mixed
     */
    public function __set($strKey, $varValue)
    {
        switch ($strKey) {
            case 'maxlength':
                $this->arrConfiguration['maxlength'] = $varValue;
                break;

            case 'mandatory':
                if ($varValue) {
                    $this->arrAttributes['required'] = 'required';
                } else {
                    unset($this->arrAttributes['required']);
                }
                parent::__set($strKey, $varValue);
                break;

            case 'fSize':
                if ($varValue > 0) {
                    $this->arrAttributes['size'] = $varValue;
                }
                break;

            case 'avatarPreviewSize':
                $this->arrAvatarPreviewSize = $varValue;
                break;

            default:
                parent::__set($strKey, $varValue);
                break;
        }
    }


    /**
     * Validate the input and set the value
     */
    public function validate()
    {
        $this->maxlength    = $GLOBALS['TL_CONFIG']['avatar_maxsize'];
        $this->extensions   = $GLOBALS['TL_CONFIG']['avatar_filetype'];
        $this->uploadFolder = $GLOBALS['TL_CONFIG']['avatar_dir'];
        $this->storeFile    = $this->uploadFolder != '' ? true : false;

        $arrImage = deserialize($GLOBALS['TL_CONFIG']['avatar_maxdims']);

        $this->import('FrontendUser', 'User');

        // No file specified
        if (!isset($_FILES[$this->strName]) || empty($_FILES[$this->strName]['name'])) {
            if ($this->mandatory) {
                if ($this->strLabel == '') {
                    $this->addError($GLOBALS['TL_LANG']['ERR']['mdtryNoLabel']);
                } else {
                    $this->addError(sprintf($GLOBALS['TL_LANG']['ERR']['mandatory'], $this->strLabel));
                }
            }

            return;
        }

        $file         = $_FILES[$this->strName];
        $maxlength_kb = $this->getReadableSize($this->maxlength);

        // Romanize the filename
        $file['name'] = utf8_romanize($file['name']);

        // File was not uploaded
        if (!is_uploaded_file($file['tmp_name'])) {
            if (in_array($file['error'], array(1, 2))) {
                $this->addError(sprintf($GLOBALS['TL_LANG']['ERR']['filesize'], $maxlength_kb));
                $this->log(
                    'File "' . $file['name'] . '" exceeds the maximum file size of ' . $maxlength_kb,
                    'FormFileUpload validate()',
                    TL_ERROR
                );
            }

            if ($file['error'] == 3) {
                $this->addError(sprintf($GLOBALS['TL_LANG']['ERR']['filepartial'], $file['name']));
                $this->log(
                    'File "' . $file['name'] . '" was only partially uploaded',
                    'FormFileUpload validate()',
                    TL_ERROR
                );
            }

            unset($_FILES[$this->strName]);
            return;
        }

        // File is too big
        if ($this->maxlength > 0 && $file['size'] > $this->maxlength) {
            $this->addError(sprintf($GLOBALS['TL_LANG']['ERR']['filesize'], $maxlength_kb));
            $this->log(
                'File "' . $file['name'] . '" exceeds the maximum file size of ' . $maxlength_kb,
                'FormFileUpload validate()',
                TL_ERROR
            );

            unset($_FILES[$this->strName]);
            return;
        }

        $strExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $uploadTypes  = trimsplit(',', $this->extensions);

        // File type is not allowed
        if (!in_array(strtolower($strExtension), $uploadTypes)) {
            $this->addError(sprintf($GLOBALS['TL_LANG']['ERR']['filetype'], $strExtension));
            $this->log(
                'File type "' . $strExtension . '" is not allowed to be uploaded (' . $file['name'] . ')',
                'FormFileUpload validate()',
                TL_ERROR
            );

            unset($_FILES[$this->strName]);
            return;
        }

        $blnResize = false;

        if (($arrImageSize = @getimagesize($file['tmp_name'])) != false) {
            // Image exceeds maximum image width
            if ($arrImageSize[0] > $arrImage[0]) {
                if ($GLOBALS['TL_CONFIG']['avatar_resize']) {
                    $blnResize = true;
                } else {
                    $this->addError(sprintf($GLOBALS['TL_LANG']['ERR']['filewidth'], $file['name'], $arrImage[0]));
                    $this->log(
                        'File "' . $file['name'] . '" exceeds the maximum image width of ' . $GLOBALS['TL_CONFIG']['imageWidth'] . ' pixels',
                        'FormFileUpload validate()',
                        TL_ERROR
                    );

                    unset($_FILES[$this->strName]);
                    return;
                }
            }

            // Image exceeds maximum image height
            if ($arrImageSize[1] > $arrImage[1]) {
                if ($GLOBALS['TL_CONFIG']['avatar_resize']) {
                    $blnResize = true;
                } else {
                    $this->addError(sprintf($GLOBALS['TL_LANG']['ERR']['fileheight'], $file['name'], $arrImage[1]));
                    $this->log(
                        'File "' . $file['name'] . '" exceeds the maximum image height of ' . $GLOBALS['TL_CONFIG']['imageHeight'] . ' pixels',
                        'FormFileUpload validate()',
                        TL_ERROR
                    );

                    unset($_FILES[$this->strName]);
                    return;
                }
            }
        }

        // Store file in the session and optionally on the server
        if (!$this->hasErrors()) {
            $_SESSION['FILES'][$this->strName] = $_FILES[$this->strName];
            $this->log('File "' . $file['name'] . '" uploaded successfully', 'FormFileUpload validate()', TL_FILES);

            if ($this->storeFile) {
                $intUploadFolder = $this->uploadFolder;

                if ($this->User->assignDir && $this->User->homeDir) {
                    $intUploadFolder = $this->User->homeDir;
                }

                $objUploadFolder = \FilesModel::findByUuid($intUploadFolder);

                // The upload folder could not be found
                if ($objUploadFolder === null) {
                    throw new \Exception("Invalid upload folder ID $intUploadFolder");
                }

                $strUploadFolder = $objUploadFolder->path;

                // Store the file if the upload folder exists
                if ($strUploadFolder != '' && is_dir(TL_ROOT . '/' . $strUploadFolder)) {
                    $this->import('Files');

                    if ($GLOBALS['TL_CONFIG']['avatar_rename']) {
                        $pathinfo   = pathinfo($file['name']);
                        $user       = \MemberModel::findByPk($this->User->id);
                        $targetName = standardize(
                                \String::parseSimpleTokens($GLOBALS['TL_CONFIG']['avatar_name'], $user->row())
                            ) . '.' . $pathinfo['extension'];
                    } else {
                        $targetName = $file['name'];
                    }

                    // Do not overwrite existing files
                    if ($this->doNotOverwrite && file_exists(TL_ROOT . '/' . $strUploadFolder . '/' . $targetName)) {
                        $offset   = 1;
                        $pathinfo = pathinfo($targetName);
                        $name     = $pathinfo['filename'];

                        $arrAll   = scan(TL_ROOT . '/' . $strUploadFolder);
                        $arrFiles = preg_grep(
                            '/^' . preg_quote($name, '/') . '.*\.' . preg_quote($pathinfo['extension'], '/') . '/',
                            $arrAll
                        );

                        foreach ($arrFiles as $strFile) {
                            if (preg_match('/__[0-9]+\.' . preg_quote($pathinfo['extension'], '/') . '$/', $strFile)) {
                                $strFile  = str_replace('.' . $pathinfo['extension'], '', $strFile);
                                $intValue = intval(substr($strFile, (strrpos($strFile, '_') + 1)));

                                $offset = max($offset, $intValue);
                            }
                        }

                        $targetName = str_replace($name, $name . '__' . ++$offset, $targetName);
                    }

                    $this->Files->move_uploaded_file($file['tmp_name'], $strUploadFolder . '/' . $targetName);
                    $this->Files->chmod(
                        $strUploadFolder . '/' . $targetName,
                        $GLOBALS['TL_CONFIG']['defaultFileChmod']
                    );

                    if ($blnResize) {
                        \Image::resize(
                            $strUploadFolder . '/' . $targetName,
                            $arrImage[0],
                            $arrImage[1],
                            $arrImage[2]
                        );
                    }

                    $_SESSION['FILES'][$this->strName] = array
                    (
                        'name'     => $targetName,
                        'type'     => $file['type'],
                        'tmp_name' => TL_ROOT . '/' . $strUploadFolder . '/' . $file['name'],
                        'error'    => $file['error'],
                        'size'     => $file['size'],
                        'uploaded' => true
                    );

                    $strFile = $strUploadFolder . '/' . $targetName;
                    $objModel = \Dbafs::addResource($strFile, true);

                    // new Avatar for Member
					$this->import('FrontendUser', 'User');
                    $this->User->avatar = $objModel->uuid;
                    $this->User->save();

                    $this->varValue = $objModel->uuid;

                    $this->log(
                        'File "' . $targetName . '" has been moved to "' . $strUploadFolder . '"',
                        __METHOD__,
                        TL_FILES
                    );
                }
            }
        }

        unset($_FILES[$this->strName]);
    }


    /**
     * Generate the widget and return it as string
     *
     * @return string
     */
    public function generate()
    {
        global $objPage;

        $this->maxlength  = $GLOBALS['TL_CONFIG']['avatar_maxsize'];
        $this->extensions = $GLOBALS['TL_CONFIG']['avatar_filetype'];
        $arrImage         = deserialize($GLOBALS['TL_CONFIG']['avatar_maxdims']);
        $arrPreviewImage  = $this->arrAvatarPreviewSize ?: $arrImage;

        $this->import('FrontendUser', 'User');

        $strAvatar = $this->User->avatar;
        $strAlt    = $this->User->firstname . " " . $this->User->lastname;

        $objFile  = \FilesModel::findByUuid($strAvatar);
        $template = '';

        if ($objFile === null && $GLOBALS['TL_CONFIG']['avatar_fallback_image']) {
            $objFile = \FilesModel::findByUuid($GLOBALS['TL_CONFIG']['avatar_fallback_image']);
        }

        if ($objFile !== null) {
            $template .= '<img src="' . TL_FILES_URL . \Image::get(
                    $objFile->path,
                    $arrPreviewImage[0],
                    $arrPreviewImage[1],
                    $arrPreviewImage[2]
                ) . '" alt="' . $strAlt . '" class="avatar">';
//               ) . '" width="' . $arrPreviewImage[0] . '" height="' . $arrPreviewImage[1] . '" alt="' . $strAlt . '" class="avatar">';
// The original line would lead to distortions in the preview
			
        } elseif ($this->User->gender != '') {
            $template .= '<img src="' . TL_FILES_URL . \Image::get(
                    "system/modules/avatar/assets/" . $this->User->gender . ".png",
                    $arrPreviewImage[0],
                    $arrPreviewImage[1],
                    $arrPreviewImage[2]
                ) . '" width="' . $arrPreviewImage[0] . '" height="' . $arrPreviewImage[1] . '" alt="Avatar" class="avatar">';
        } else {
            $template .= '<img src="' . TL_FILES_URL . \Image::get(
                    "system/modules/avatar/assets/male.png",
                    $arrPreviewImage[0],
                    $arrPreviewImage[1],
                    $arrPreviewImage[2]
                ) . '" width="' . $arrImage[0] . '" height="' . $arrPreviewImage[1] . '" alt="Avatar" class="avatar">';
        }

        $template .= sprintf(
            '<input type="file" name="%s" id="ctrl_%s" class="upload%s"%s%s',
            $this->strName,
            $this->strId,
            (strlen($this->strClass) ? ' ' . $this->strClass : ''),
            $this->getAttributes(),
            $this->strTagEnding
        );

        $template .= sprintf(
            $GLOBALS['TL_LANG']['AVATAR']['file']['1'],
            $this->extensions,
            $this->maxlength,
            $arrImage[0],
            $arrImage[1]
        );

        return $template;
    }
}  
