<div class="one-image">
    <a href="$PathFromPublicRoot" target="_blank" class="main-link">view</a>
    <% if $IsImage %>
        <% if $IsProtected %>
            [PROTECTED]
        <% else %>
        <img src="$PathFromPublicRoot" height="250" alt="$DBTitle" />
        <% end_if %>
    <% else %>
        <span class="main-title">[$DBTitle]</span>
        <br />
        <span class="sub-title">[$PathExtension]</span>
        <br />
        <% if $IsProtected %>
            <span class="sub-title">[PROTECTED]</span>
        <% end_if %>
    <% end_if %>

    <% if $DBErrorDBNotPresent %>
    <br />[NOT PRESENT IN DB]
    <% else %>
    <a href="$DBCMSEditLink" class="edit-icon" target="_blank">✎</a>
    <% end_if %>
    <br /><a href="$InfoLink" class="info-icon" target="_blank">ℹ</a>
    <div class="one-image-info">
        <p>
            <u>$DBTitle</u>
        </p>
        <% if $ErrorIsInFileSystem %>
            <p><strong>Not in file-system</strong></p>
        <% else %>
            <p><strong>Folder:</strong> <a href="$FolderCMSEditLink" target="_blank">✎ $PathFolderFromAssets</a></p>
            <p><strong>File: </strong>$PathFileNameWithoutExtension . $PathExtension</p>
        <% end_if %>

        <p><strong>Last Changed:</strong> $DBLastEdited</p>

        <% if $IsImage %>
        <p><strong>Dimensions:</strong> $HumanImageDimensions</p>
        <% end_if %>

        <% if $IsDir %>
        <p><strong>Is Directory</strong></p>
        <% else %>
        <p><strong>Size:</strong> $HumanFileSize</p>
        <% end_if %>

        <p><strong>DB:</strong> $HumanErrorDBNotPresent</p>
    </div>
</div>
