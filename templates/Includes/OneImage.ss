<div class="one-image">
    <a href="$PathFromPublicRoot" target="_blank" class="main-link">
    </a>
    <% if $ImageIsImage %>
        <img src="$PathFromPublicRoot" height="250" alt="$DBTitle" />
    <% else %>
        <span class="main-title">[$DBTitle]</span>
        <br />
        <span class="sub-title">[$PathExtension]</span>
    <% end_if %>

    <% if $DBErrorDBNotPresent %>
    <a href="$DBCMSEditLink" class="edit-icon" target="_blank">✎</a>
    <% end_if %>
    <div class="one-image-info">
        <% if $ImageIsImage %>
            <p>
                <u>$DBTitle</u>
            </p>
        <% end_if %>
        <% if $ErrorIsInFileSystem %>
            <p><strong>Not in file-system</strong></p>
        <% else %>
            <p><strong>Folder:</strong> <a href="$FolderCMSEditLink" target="_blank">✎ $PathFolderFromAssets</a></p>
            <p><strong>File: </strong>$PathFileName . $PathExtension</p>
        <% end_if %>
        <p><strong>Last Changed:</strong> $DBLastEdited</p>
        <p><strong>Dimensions:</strong> $HumanImageDimensions</p>
        <p><strong>Size:</strong> $HumanFileSize</p>
        <p><strong>DB:</strong> $HumanErrorDBNotPresent</p>
    </div>
</div>
